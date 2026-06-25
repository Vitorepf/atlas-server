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

        $blockers = [];
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_missing';
        }
        if ($changed === []) {
            $blockers[] = 'changed_files_missing';
        }
        if (array_values(array_diff($changed, $allowed)) !== []) {
            $blockers[] = 'changed_files_outside_allowed_scope';
        }

        return [
            'schema_version' => self::SCHEMA,
            'phase' => 'verification_request',
            'task_packet_id' => $taskId,
            'allowed_files' => $allowed,
            'changed_files' => $changed,
            'gate_outputs' => $gates,
            'evidence_refs' => $evidenceRefs,
            'blockers' => array_values($blockers),
            'ready_for_court' => $blockers === [],
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
        $evidenceRefs = array_values((array) ($verificationVerdict['evidence_refs'] ?? []));

        if (! $passed) {
            return $this->envelope(self::DECISION_REJECT, ['verification_failed']);
        }
        $blockers = [];
        if ($falseGreen) {
            $blockers[] = 'false_green_risk_detected';
        }
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_missing_post_verify';
        }
        if ($rollbackPlan === [] || ! isset($rollbackPlan['mode'])) {
            $blockers[] = 'rollback_plan_missing';
        }
        if ($blockers !== []) {
            return $this->envelope(self::DECISION_BLOCK, $blockers);
        }

        return $this->envelope(self::DECISION_REQUEST_MERGE, []);
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
