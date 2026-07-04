<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Pure release preflight. Validates a proposed native diff against:
 *   - allowed_files (every changed path must be in the allow-list)
 *   - forbidden_targets (no changed path may match a forbidden entry)
 *   - required_evidence (verification + rollback evidence must be present)
 *   - rollback_preimage (must be present for every modified file)
 *   - merge-governor risk facts (high_risk_change must be matched by extra evidence)
 *
 * NEVER applies the diff.
 *
 * Output: {schema_version, decision:'allow'|'reject'|'needs_more_evidence', blockers:list<string>}
 */
final class AtlasSelfConstructionNativeImplementationReleasePreflight
{
    public const SCHEMA = 'atlas.native_implementation.release_preflight.v1';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_REJECT = 'reject';

    public const DECISION_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';

    /**
     * @param  array<string,mixed>  $proposal {
     *     changed_files: list<string>,
     *     allowed_files: list<string>,
     *     forbidden_targets: list<string>,
     *     required_evidence: list<string>,
     *     evidence_refs: list<string>,
     *     rollback_preimage: array<string,string>,    // path => previous_hash
     *     merge_governor: {high_risk_change?:bool, extra_evidence_required?:list<string>},
     *   }
     * @return array<string,mixed>
     */
    public function preflight(array $proposal): array
    {
        $changed = array_values((array) ($proposal['changed_files'] ?? []));
        $allowed = array_values((array) ($proposal['allowed_files'] ?? []));
        $forbidden = array_values((array) ($proposal['forbidden_targets'] ?? []));
        $required = array_values((array) ($proposal['required_evidence'] ?? []));
        $evidenceRefs = array_values((array) ($proposal['evidence_refs'] ?? []));
        $rollbackPreimage = (array) ($proposal['rollback_preimage'] ?? []);
        $mg = (array) ($proposal['merge_governor'] ?? []);

        $rejectBlockers = [];
        $needsMoreBlockers = [];

        // REJECT — empty change set carries no evidence and must not be auto-allowed.
        if ($changed === []) {
            $rejectBlockers[] = 'empty_changed_files';
        }

        // REJECT — out-of-scope or forbidden changes (with alias normalization).
        $normalizedForbidden = array_map($this->normalizePath(...), $forbidden);
        foreach ($changed as $c) {
            if (! in_array($c, $allowed, true)) {
                $rejectBlockers[] = 'change_outside_allowed_files:'.$c;
            }
            if (in_array($this->normalizePath($c), $normalizedForbidden, true)) {
                $rejectBlockers[] = 'change_in_forbidden_targets:'.$c;
            }
        }

        // NEEDS MORE — missing evidence (no scope violation).
        $coveredKinds = [];
        foreach ($evidenceRefs as $ref) {
            $kind = (string) $ref;
            $colonPos = strpos($kind, ':');
            if ($colonPos !== false) {
                $kind = substr($kind, 0, $colonPos);
            }
            $coveredKinds[$kind] = true;
        }
        $missingEvidence = [];
        foreach ($required as $r) {
            if (! isset($coveredKinds[$r])) {
                $missingEvidence[] = (string) $r;
            }
        }
        if ($missingEvidence !== []) {
            $needsMoreBlockers[] = 'missing_evidence_kinds:'.implode(',', $missingEvidence);
        }

        $missingPreimage = [];
        foreach ($changed as $c) {
            if (! isset($rollbackPreimage[$c])) {
                $missingPreimage[] = $c;
            }
        }
        if ($missingPreimage !== []) {
            $needsMoreBlockers[] = 'missing_rollback_preimage:'.implode(',', $missingPreimage);
        }

        if ((bool) ($mg['high_risk_change'] ?? false)) {
            foreach ((array) ($mg['extra_evidence_required'] ?? []) as $k) {
                if (! isset($coveredKinds[(string) $k])) {
                    $needsMoreBlockers[] = 'high_risk_missing_evidence:'.(string) $k;
                }
            }
        }

        // REJECT — autonomy proof floor: proposal must be Atlas-native; no external actor dependency.
        foreach (['requires_human', 'requires_operator', 'requires_external_provider'] as $flag) {
            if ((bool) ($proposal[$flag] ?? false)) {
                $rejectBlockers[] = 'autonomy_violation:'.$flag.'_must_be_false';
            }
        }
        $finalOwner = (string) ($proposal['final_runtime_owner'] ?? '');
        if ($finalOwner !== '' && $finalOwner !== 'atlas_native') {
            $rejectBlockers[] = 'autonomy_violation:final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        // NEEDS_MORE — bounded_rollback is a mandatory autonomy proof floor requirement;
        // it must be evidenced regardless of what the caller lists in required_evidence.
        if (! isset($coveredKinds['bounded_rollback'])) {
            $needsMoreBlockers[] = 'autonomy_proof_floor_missing:bounded_rollback';
        }

        if ($rejectBlockers !== []) {
            return $this->envelope(self::DECISION_REJECT, array_values($rejectBlockers));
        }
        if ($needsMoreBlockers !== []) {
            return $this->envelope(self::DECISION_NEEDS_MORE_EVIDENCE, array_values($needsMoreBlockers));
        }

        return $this->envelope(self::DECISION_ALLOW, []);
    }

    private function normalizePath(string $path): string
    {
        // Strip leading ./ and collapse repeated slashes for alias-safe comparison.
        return ltrim(preg_replace('#/+#', '/', $path) ?? $path, './');
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(string $decision, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Release preflight: blocks release when task_quality, proof_freshness, scope_isolation,
     * rollback or knowledge_sync readiness is missing. Allows release only with provider-safe evidence refs.
     *
     * @param  array{
     *   task_quality: float,
     *   proof_freshness: float,
     *   scope_isolation: bool,
     *   rollback_readiness: bool,
     *   knowledge_sync_readiness: bool,
     *   evidence_refs: list<string>,
     *   task_quality_floor: float,
     *   proof_freshness_floor: float,
     * }  $input
     * @return array{release_ready:bool, blocking_gates:list<string>, evidence_refs:list<string>, next_unblock_action:string}
     */
    public function releasePreflight(array $input): array
    {
        $taskQuality = (float) ($input['task_quality'] ?? 0.0);
        $proofFreshness = (float) ($input['proof_freshness'] ?? 0.0);
        $scopeIsolation = (bool) ($input['scope_isolation'] ?? false);
        $rollbackReadiness = (bool) ($input['rollback_readiness'] ?? false);
        $knowledgeSyncReadiness = (bool) ($input['knowledge_sync_readiness'] ?? false);
        $evidenceRefs = is_array($input['evidence_refs'] ?? null) ? $input['evidence_refs'] : [];
        $taskQualityFloor = (float) ($input['task_quality_floor'] ?? 0.7);
        $proofFreshnessFloor = (float) ($input['proof_freshness_floor'] ?? 0.5);

        $blockingGates = [];

        if ($taskQuality < $taskQualityFloor) {
            $blockingGates[] = sprintf('task_quality=%.4f below floor=%.4f', $taskQuality, $taskQualityFloor);
        }
        if ($proofFreshness < $proofFreshnessFloor) {
            $blockingGates[] = sprintf('proof_freshness=%.4f below floor=%.4f', $proofFreshness, $proofFreshnessFloor);
        }
        if (!$scopeIsolation) {
            $blockingGates[] = 'scope_isolation_not_verified';
        }
        if (!$rollbackReadiness) {
            $blockingGates[] = 'rollback_readiness_missing';
        }
        if (!$knowledgeSyncReadiness) {
            $blockingGates[] = 'knowledge_sync_readiness_missing';
        }
        if ($evidenceRefs === []) {
            $blockingGates[] = 'no_provider_safe_evidence_refs';
        }

        $releaseReady = $blockingGates === [];

        $nextUnblockAction = match (true) {
            $releaseReady => 'proceed_with_release',
            in_array('scope_isolation_not_verified', $blockingGates, true) => 'verify_scope_isolation',
            in_array('rollback_readiness_missing', $blockingGates, true) => 'prepare_rollback_plan',
            in_array('knowledge_sync_readiness_missing', $blockingGates, true) => 'sync_knowledge_base',
            default => 'address_blocking_gates',
        };

        return [
            'release_ready' => $releaseReady,
            'blocking_gates' => $blockingGates,
            'evidence_refs' => $evidenceRefs,
            'next_unblock_action' => $nextUnblockAction,
        ];
    }
}
