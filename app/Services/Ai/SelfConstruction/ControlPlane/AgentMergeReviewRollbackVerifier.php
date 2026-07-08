<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Verifies that each rollback step in a dry-run promotion plan is
 * reachable and reversible against the original packet. Pure
 * projection — never executes a rollback, never restores any file.
 */
final class AgentMergeReviewRollbackVerifier
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_rollback_verification.v1';

    public const MODE = 'read_only_agent_merge_review_rollback_verification';

    public const CONFIDENCE_BANDS = ['low', 'medium', 'high'];

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_rollback_verifier_does_not_execute_rollback',
        'agent_merge_review_rollback_verifier_does_not_restore_files',
        'agent_merge_review_rollback_verifier_does_not_apply_patch',
        'agent_merge_review_rollback_verifier_does_not_advance_completion_claim',
        'agent_merge_review_rollback_verifier_does_not_dispatch_agent',
        'agent_merge_review_rollback_verifier_does_not_write_ledger',
    ];

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $promotionDryRun
     * @return array<string, mixed>
     */
    public function verify(array $packet, array $promotionDryRun): array
    {
        $files = (array) data_get($packet, 'packet.files', []);
        $rollbackSteps = (array) data_get($promotionDryRun, 'plan.rollback_steps', []);
        $pathHashByPath = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $pathHashByPath[$path] = [
                'change_kind' => (string) ($file['change_kind'] ?? 'modified'),
                'content_hash' => $file['content_hash'] ?? null,
                'previous_path' => $file['previous_path'] ?? null,
            ];
        }

        $verifiedSteps = [];
        $unverifiedSteps = [];
        $missingHashSteps = [];
        foreach ($rollbackSteps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $path = (string) ($step['path'] ?? '');
            $kind = (string) ($step['change_kind'] ?? 'modified');
            if ($path === '') {
                if (str_starts_with((string) ($step['name'] ?? ''), 'discard_isolated_worktree')) {
                    $verifiedSteps[] = [
                        'name' => (string) ($step['name'] ?? ''),
                        'path' => null,
                        'change_kind' => null,
                        'reversibility' => 'worktree_scope_only',
                    ];

                    continue;
                }
                $unverifiedSteps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'reason' => 'rollback_step_missing_path',
                ];

                continue;
            }
            if (! array_key_exists($path, $pathHashByPath)) {
                $unverifiedSteps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'path' => $path,
                    'reason' => 'rollback_step_references_unknown_path',
                ];

                continue;
            }
            $meta = $pathHashByPath[$path];
            if ($meta['change_kind'] !== $kind) {
                $unverifiedSteps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'path' => $path,
                    'reason' => 'rollback_step_change_kind_mismatch',
                    'expected_change_kind' => $meta['change_kind'],
                    'observed_change_kind' => $kind,
                ];

                continue;
            }
            if ($meta['content_hash'] === null) {
                $missingHashSteps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'path' => $path,
                    'change_kind' => $kind,
                    'reason' => 'rollback_step_path_has_no_content_hash',
                ];
                $verifiedSteps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'path' => $path,
                    'change_kind' => $kind,
                    'reversibility' => 'reversible_without_hash_check',
                ];

                continue;
            }
            $verifiedSteps[] = [
                'name' => (string) ($step['name'] ?? ''),
                'path' => $path,
                'change_kind' => $kind,
                'reversibility' => 'reversible_with_hash_check',
            ];
        }

        $rollbackCoverage = count($files) === 0 ? 1.0 : min(1.0, count($this->verifiedFilePaths($verifiedSteps)) / max(1, count($files)));
        $confidence = $this->confidence(count($unverifiedSteps), $rollbackCoverage, count($missingHashSteps));
        $status = count($unverifiedSteps) === 0 ? 'agent_merge_review_rollback_verified' : 'agent_merge_review_rollback_verification_incomplete';

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'rollback_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'verification' => [
                'verified_steps' => $verifiedSteps,
                'verified_step_count' => count($verifiedSteps),
                'unverified_steps' => $unverifiedSteps,
                'unverified_step_count' => count($unverifiedSteps),
                'missing_hash_steps' => $missingHashSteps,
                'missing_hash_step_count' => count($missingHashSteps),
                'rollback_coverage_ratio' => $rollbackCoverage,
                'confidence' => $confidence,
                'all_steps_reversible' => count($unverifiedSteps) === 0,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['verification_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    /**
     * @param  list<array<string, mixed>>  $verifiedSteps
     * @return list<string>
     */
    private function verifiedFilePaths(array $verifiedSteps): array
    {
        $paths = [];
        foreach ($verifiedSteps as $step) {
            $path = $step['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    private function confidence(int $unverifiedCount, float $coverage, int $missingHashCount): string
    {
        if ($unverifiedCount > 0) {
            return 'low';
        }
        if ($missingHashCount > 0) {
            return 'medium';
        }
        if ($coverage >= 0.999) {
            return 'high';
        }

        return 'medium';
    }

    public const DECISION_ROLLBACK_READY      = 'rollback_ready';
    public const DECISION_AUTONOMOUS_SAFE     = 'autonomous_safe';
    public const DECISION_REQUIRE_MANUAL_PLAN = 'require_manual_plan';
    public const DECISION_REJECT_MERGE        = 'reject_merge';

    private const MIGRATION_PATH_MARKERS = ['database/migrations/', 'schema/'];
    private const GENERATED_CHANGE_KINDS = ['generated'];

    /**
     * Classify rollback readiness from changed files, migration/schema risk,
     * generated artifacts and the verify() evidence — never depends on human
     * approval in steady state; decisions are derived purely from evidence.
     *
     * Returns autonomous_safe only when rollback evidence covers every changed
     * file and risky operation. Detects stale proof (evidence older than the
     * changed files) and includes blockers when evidence is incomplete or stale.
     *
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $promotionDryRun  may include recovery_evidence list, proof_generated_at
     * @return array{schema_version:string, decision:string, rollback_reason:string, required_recovery_evidence:list<string>, blockers:list<string>}
     */
    public function classifyRollbackReadiness(array $packet, array $promotionDryRun): array
    {
        $verification = $this->verify($packet, $promotionDryRun);
        $unverifiedCount = $verification['verification']['unverified_step_count'];
        $coverage = (float) ($verification['verification']['rollback_coverage_ratio'] ?? 0.0);

        $files = (array) data_get($packet, 'packet.files', []);
        $providedEvidence = (array) ($promotionDryRun['recovery_evidence'] ?? []);
        $blockers = [];

        $hasMigrationRisk = false;
        $hasGeneratedArtifact = false;
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $changeKind = (string) ($file['change_kind'] ?? '');
            if ((bool) ($file['schema_risk'] ?? false) || $this->matchesAny($path, self::MIGRATION_PATH_MARKERS)) {
                $hasMigrationRisk = true;
            }
            if (in_array($changeKind, self::GENERATED_CHANGE_KINDS, true)) {
                $hasGeneratedArtifact = true;
            }
        }

        // Stale proof detection: if proof_generated_at is set, compare to the
        // latest change timestamp in the packet.
        $proofGeneratedAt = (string) ($promotionDryRun['proof_generated_at'] ?? '');
        $latestChangeAt = '';
        foreach ($files as $file) {
            $changedAt = (string) ($file['changed_at'] ?? '');
            if ($changedAt > $latestChangeAt) {
                $latestChangeAt = $changedAt;
            }
        }
        $proofStale = false;
        if ($proofGeneratedAt !== '' && $latestChangeAt !== '') {
            $proofStale = $proofGeneratedAt < $latestChangeAt;
        }
        if ($proofStale) {
            $blockers[] = 'stale_proof:rollback_evidence_older_than_latest_file_change';
        }

        $requiredEvidence = [];
        if ($hasMigrationRisk) {
            $requiredEvidence[] = 'db_backup_snapshot_ref';
        }
        if ($hasGeneratedArtifact) {
            $requiredEvidence[] = 'regenerate_command';
        }

        $missingEvidence = array_values(array_diff($requiredEvidence, $providedEvidence));

        if ($proofStale) {
            $blockers[] = 'stale_proof';
        }

        // Stale proof blocks autonomous merge — require manual plan.
        if ($proofStale && $unverifiedCount === 0 && $missingEvidence === [] && $coverage >= 0.999) {
            return [
                'schema_version'              => self::SCHEMA_VERSION,
                'decision'                    => self::DECISION_REQUIRE_MANUAL_PLAN,
                'rollback_reason'             => 'rollback evidence is stale relative to the latest file change',
                'required_recovery_evidence'  => $requiredEvidence,
                'blockers'                    => $blockers,
            ];
        }

        // Unrollbackable steps + migration/schema risk together: too dangerous to
        // proceed autonomously — reject outright rather than hope a manual plan helps.
        if ($unverifiedCount > 0 && $hasMigrationRisk) {
            $blockers[] = 'unverifiable_step_with_migration_risk';
            $blockers[] = 'missing_recovery_evidence';

            return [
                'schema_version'              => self::SCHEMA_VERSION,
                'decision'                    => self::DECISION_REJECT_MERGE,
                'rollback_reason'             => 'unverifiable rollback steps combined with migration/schema risk make this change unsafe to merge autonomously',
                'required_recovery_evidence'  => $requiredEvidence,
                'blockers'                    => $blockers,
            ];
        }

        if ($unverifiedCount > 0) {
            $blockers[] = 'unverified_rollback_steps';

            return [
                'schema_version'              => self::SCHEMA_VERSION,
                'decision'                    => self::DECISION_REQUIRE_MANUAL_PLAN,
                'rollback_reason'             => sprintf('%d rollback step(s) could not be verified against the packet', $unverifiedCount),
                'required_recovery_evidence'  => $requiredEvidence,
                'blockers'                    => $blockers,
            ];
        }

        if ($missingEvidence !== []) {
            $blockers[] = 'missing_recovery_evidence';

            return [
                'schema_version'              => self::SCHEMA_VERSION,
                'decision'                    => self::DECISION_REQUIRE_MANUAL_PLAN,
                'rollback_reason'             => 'migration/schema risk or generated artifacts present without the required recovery evidence',
                'required_recovery_evidence'  => $requiredEvidence,
                'blockers'                    => $blockers,
            ];
        }

        // Autonomous-safe: all steps verified, no missing evidence, no stale proof,
        // and rollback coverage covers every changed file.
        if ($coverage < 0.999) {
            $blockers[] = 'incomplete_rollback_coverage';

            return [
                'schema_version'              => self::SCHEMA_VERSION,
                'decision'                    => self::DECISION_REQUIRE_MANUAL_PLAN,
                'rollback_reason'             => 'rollback coverage does not cover every changed file',
                'required_recovery_evidence'  => $requiredEvidence,
                'blockers'                    => $blockers,
            ];
        }

        return [
            'schema_version'              => self::SCHEMA_VERSION,
            'decision'                    => self::DECISION_AUTONOMOUS_SAFE,
            'rollback_reason'             => 'rollback evidence covers every changed file and risky operation; no stale proof detected',
            'required_recovery_evidence'  => $requiredEvidence,
            'blockers'                    => $blockers,
        ];
    }

    /** @param  list<string>  $markers */
    private function matchesAny(string $path, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($path, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['verification_hash']);

        $encoded = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed on rollback verification envelope — cannot produce fingerprint hash');
        }

        return hash('sha256', $encoded);
    }
}
