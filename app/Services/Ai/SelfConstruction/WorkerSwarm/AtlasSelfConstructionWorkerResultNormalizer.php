<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\WorkerSwarm;

/**
 * Pure normalizer. Converts a worker's raw result into the canonical FACTS-only input the Verification
 * Court consumes:
 *
 *   {schema_version, task_id, lease_id, claimed_outcome, court_status:'verified_pass'|'verified_fail'|
 *     'pending_review'|'blocked', verified_evidence:list<string>, unverified_claims:list<string>,
 *    out_of_scope_files:list<string>, blockers:list<string>}
 *
 * Contract:
 *   - SEPARATES the worker's claimed_outcome from VERIFIED evidence. A claim is only verified when
 *     every required_evidence_kind has a matching evidence ref AND every gate output passed.
 *   - When changed_files escape the task.allowed_files allow-list ⇒ status=blocked.
 *   - When required_evidence_kinds are unmatched but no scope violation ⇒ status=pending_review.
 *   - NEVER attempts to "fix" the worker's outcome — it labels and forwards.
 */
final class AtlasSelfConstructionWorkerResultNormalizer
{
    public const SCHEMA = 'atlas.worker_swarm.result_normalizer.v1';

    public const STATUS_VERIFIED_PASS = 'verified_pass';

    public const STATUS_VERIFIED_FAIL = 'verified_fail';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $task    {task_id, lease_id, allowed_files, required_evidence_kinds}
     * @param  array<string,mixed>  $result  {claimed_outcome, changed_files, gate_outputs, evidence_refs}
     * @return array<string,mixed>
     */
    public function normalize(array $task, array $result): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $leaseId = (string) ($task['lease_id'] ?? '');
        $allowed = array_values((array) ($task['allowed_files'] ?? []));
        $requiredEvidence = array_values((array) ($task['required_evidence_kinds'] ?? []));

        $claimedOutcome = (string) ($result['claimed_outcome'] ?? 'unknown');
        $changedFiles = array_values((array) ($result['changed_files'] ?? []));
        $gateOutputs = (array) ($result['gate_outputs'] ?? []);
        $evidenceRefs = array_values((array) ($result['evidence_refs'] ?? []));

        $blockers = [];

        // SCOPE check first — a scope violation invalidates everything else.
        $outOfScope = array_values(array_diff($changedFiles, $allowed));
        if ($outOfScope !== []) {
            $blockers[] = 'changed_files_outside_allowed_scope:'.implode(',', $outOfScope);
        }

        // Evidence verification — each required_evidence_kind must have at least one supporting ref
        // (refs of the form "<kind>:<id>").
        $coveredKinds = [];
        foreach ($evidenceRefs as $ref) {
            $kind = (string) $ref;
            if (str_contains($kind, ':')) {
                $kind = substr($kind, 0, strpos($kind, ':') ?: null);
            }
            $coveredKinds[$kind] = true;
        }
        $missingEvidence = array_values(array_diff($requiredEvidence, array_keys($coveredKinds)));

        // Gate outputs — every gate must have passed === true for verified status.
        $failedGates = [];
        foreach ($gateOutputs as $gate => $output) {
            if (! (bool) (is_array($output) ? ($output['passed'] ?? false) : $output)) {
                $failedGates[] = (string) $gate;
            }
        }

        if ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
            $verifiedEvidence = [];
            $unverifiedClaims = [$claimedOutcome];
        } elseif ($missingEvidence !== [] || $failedGates !== []) {
            if ($failedGates !== []) {
                $blockers[] = 'gates_failed:'.implode(',', $failedGates);
                $status = self::STATUS_VERIFIED_FAIL;
                $verifiedEvidence = $evidenceRefs;
                $unverifiedClaims = [$claimedOutcome];
            } else {
                $status = self::STATUS_PENDING_REVIEW;
                $verifiedEvidence = $evidenceRefs;
                $unverifiedClaims = ['missing_evidence_for:'.implode(',', $missingEvidence)];
            }
        } else {
            $status = self::STATUS_VERIFIED_PASS;
            $verifiedEvidence = $evidenceRefs;
            $unverifiedClaims = [];
        }

        return [
            'schema_version' => self::SCHEMA,
            'task_id' => $taskId,
            'lease_id' => $leaseId,
            'claimed_outcome' => $claimedOutcome,
            'court_status' => $status,
            'verified_evidence' => $verifiedEvidence,
            'unverified_claims' => array_values($unverifiedClaims),
            'out_of_scope_files' => $outOfScope,
            'blockers' => array_values($blockers),
        ];
    }
}
