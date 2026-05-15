<?php

namespace App\Services\Ai\SelfConstruction;

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

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['verification_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
