<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure integration. Two-stage:
 *
 *   1) buildVerificationRequest(workerEvidence)  ⇒ a Verification Court request fact map.
 *   2) buildMergeDecision(verificationVerdict, rollbackPlan) ⇒ a Merge Governor decision fact map,
 *      ONLY produced when the verification verdict reports passed=true. When the verification
 *      verdict reports passed=false OR false-green risk OR rollback_plan absent, the merge decision
 *      is `block` with named blockers.
 *
 * Pure: NEVER calls a verifier, NEVER touches git/main, NEVER writes ledgers.
 */
final class AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration
{
    public const SCHEMA = 'atlas.continuous_runtime.verification_merge.v1';

    public const DECISION_REQUEST_MERGE = 'request_merge';

    public const DECISION_REJECT = 'reject';

    public const DECISION_BLOCK = 'block';

    public const SAFE_ROLLBACK_MODES = ['feature_flag_off', 'hotfix_branch', 'revert_commit'];

    /**
     * @param  array<string,mixed>  $workerEvidence
     * @return array<string,mixed>
     */
    public function buildVerificationRequest(array $workerEvidence): array
    {
        $taskId = (string) ($workerEvidence['task_packet_id'] ?? '');
        $allowed = array_values((array) ($workerEvidence['allowed_files'] ?? []));
        $changed = array_values((array) ($workerEvidence['changed_files'] ?? []));
        $gates = array_values((array) ($workerEvidence['gate_outputs'] ?? []));
        $evidenceRefs = array_values((array) ($workerEvidence['evidence_refs'] ?? []));

        $touchedScopes    = $this->deriveTouchedScopes($changed);
        $runnableProofRefs = $this->filterRunnableProofRefs($evidenceRefs);
        $riskTier         = $this->deriveRiskTier($changed, $allowed);
        $rollbackReadiness = (string) ($workerEvidence['rollback_readiness'] ?? 'unknown');

        $blockers = [];
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_missing';
        }
        // Only fire when evidence exists but none qualify as a runnable proof.
        if ($evidenceRefs !== [] && $runnableProofRefs === []) {
            $blockers[] = 'runnable_proof_refs_missing';
        }
        if ($changed === []) {
            $blockers[] = 'changed_files_missing';
        }
        if (array_values(array_diff($changed, $allowed)) !== []) {
            $blockers[] = 'changed_files_outside_allowed_scope';
        }
        if ($gates === []) {
            $blockers[] = 'gate_outputs_missing';
        } elseif (! in_array(true, $gates, true)) {
            $blockers[] = 'gate_outputs_no_passed_fact';
        }

        return [
            'schema_version'    => self::SCHEMA,
            'phase'             => 'verification_request',
            'task_packet_id'    => $taskId,
            'allowed_files'     => $allowed,
            'changed_files'     => $changed,
            'gate_outputs'      => $gates,
            'evidence_refs'     => $evidenceRefs,
            'touched_scopes'    => $touchedScopes,
            'runnable_proof_refs' => $runnableProofRefs,
            'risk_tier'         => $riskTier,
            'rollback_readiness' => $rollbackReadiness,
            'blockers'          => array_values($blockers),
            'ready_for_court'   => $blockers === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $verificationVerdict  {passed:bool, false_green_risk?:bool, evidence_refs?:list<string>}
     * @param  array<string,mixed>  $rollbackPlan
     * @return array<string,mixed>
     */
    public function buildMergeDecision(array $verificationVerdict, array $rollbackPlan): array
    {
        $passed = (bool) ($verificationVerdict['passed'] ?? false);
        $falseGreen = (bool) ($verificationVerdict['false_green_risk'] ?? false);
        $stale = (bool) ($verificationVerdict['stale'] ?? false);
        $evidenceRefs = array_values((array) ($verificationVerdict['evidence_refs'] ?? []));
        // Only block when the caller explicitly declares runnable_proof_refs (fail-closed on declared absence).
        $runnableProofRefs = array_key_exists('runnable_proof_refs', $verificationVerdict)
            ? array_values((array) $verificationVerdict['runnable_proof_refs'])
            : null;

        if (! $passed) {
            return $this->envelope(self::DECISION_REJECT, ['verification_failed']);
        }
        $blockers = [];
        if ($stale) {
            $blockers[] = 'verification_stale';
        }
        if ($falseGreen) {
            $blockers[] = 'false_green_risk_detected';
        }
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_missing_post_verify';
        }
        if ($runnableProofRefs !== null && $runnableProofRefs === []) {
            $blockers[] = 'runnable_proof_missing_in_worker_evidence';
        }
        if ($rollbackPlan === [] || ! isset($rollbackPlan['mode'])) {
            $blockers[] = 'rollback_plan_missing';
        } elseif (! in_array((string) $rollbackPlan['mode'], self::SAFE_ROLLBACK_MODES, true)) {
            $blockers[] = 'rollback_mode_not_safe';
        }
        if ($blockers !== []) {
            return $this->envelope(self::DECISION_BLOCK, $blockers);
        }

        return $this->envelope(self::DECISION_REQUEST_MERGE, []);
    }

    /** @return list<string> */
    private function deriveTouchedScopes(array $paths): array
    {
        $scopes = [];
        foreach ($paths as $p) {
            $dir = dirname((string) $p);
            if ($dir !== '.' && $dir !== '') {
                $scopes[] = $dir;
            }
        }

        return array_values(array_unique($scopes));
    }

    private function deriveRiskTier(array $changed, array $allowed): string
    {
        $count = count($changed);
        if ($count === 0) {
            return 'none';
        }
        $ratio = $allowed !== [] ? $count / count($allowed) : 1.0;
        if ($ratio >= 0.8 || $count > 5) {
            return 'high';
        }
        if ($ratio >= 0.4 || $count > 2) {
            return 'medium';
        }

        return 'low';
    }

    /** @return list<string> */
    private function filterRunnableProofRefs(array $refs): array
    {
        return array_values(array_filter(
            array_map('strval', $refs),
            static fn (string $r): bool =>
                str_starts_with($r, 'phpunit:') ||
                str_starts_with($r, 'artisan:') ||
                str_starts_with($r, 'tests_or_gates:'),
        ));
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(string $decision, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'phase' => 'merge_decision',
            'decision' => $decision,
            'blockers' => array_values($blockers),
        ];
    }
}
