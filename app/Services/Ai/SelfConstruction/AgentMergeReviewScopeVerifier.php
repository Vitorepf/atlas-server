<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Verifies whether the paths in a merge review packet stay inside the
 * declared scope (allowed_files / scope_in) and avoid forbidden axes.
 * Pure projection — never edits scope, never applies any change.
 */
final class AgentMergeReviewScopeVerifier
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_scope_verification.v1';

    public const MODE = 'read_only_agent_merge_review_scope_verification';

    public const CROSS_AXIS_BLOCKERS = [
        'self_improvement' => 'app/Services/Ai/SelfImprovement/',
        'programming' => 'app/Services/Ai/Programming/',
        'atlas_code_controllers' => 'app/Http/Controllers/AtlasCode',
        'routes_api' => 'routes/api.php',
        'atlas_desktop' => 'atlas-desktop/',
        'forge' => 'forge/',
        'rivals' => 'rivals/',
        'cartografia' => 'cartografia/',
        'voice' => 'voice/',
    ];

    public const UNSAFE_PATH_PREFIXES = [
        '../',
        '/etc/',
        '/var/log/',
        '~/',
        '.git/',
        '.env',
        'storage/framework/',
        'vendor/',
        'node_modules/',
    ];

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_scope_verifier_does_not_edit_scope',
        'agent_merge_review_scope_verifier_does_not_apply_patch',
        'agent_merge_review_scope_verifier_does_not_modify_real_files',
        'agent_merge_review_scope_verifier_does_not_advance_completion_claim',
        'agent_merge_review_scope_verifier_does_not_write_ledger',
    ];

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $declaredScope
     * @return array<string, mixed>
     */
    public function verify(array $packet, array $declaredScope = []): array
    {
        $files = (array) data_get($packet, 'packet.files', []);
        $allowed = $this->normalizePaths((array) ($declaredScope['allowed_files'] ?? []));
        $forbidden = $this->normalizePaths((array) ($declaredScope['forbidden_files'] ?? []));
        $scopeIn = $this->normalizePaths((array) ($declaredScope['scope_in'] ?? []));
        $scopeOut = $this->normalizePaths((array) ($declaredScope['scope_out'] ?? []));

        $inScope = [];
        $outOfScope = [];
        $forbiddenViolations = [];
        $crossAxisViolations = [];
        $unsafePathViolations = [];

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }

            if ($this->matchesAny($path, $forbidden) || $this->matchesAny($path, $scopeOut)) {
                $forbiddenViolations[] = [
                    'path' => $path,
                    'change_kind' => (string) ($file['change_kind'] ?? 'modified'),
                    'reason' => 'path_matched_forbidden_or_scope_out',
                ];
            }

            foreach (self::CROSS_AXIS_BLOCKERS as $axis => $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $crossAxisViolations[] = [
                        'axis' => $axis,
                        'path' => $path,
                        'change_kind' => (string) ($file['change_kind'] ?? 'modified'),
                    ];
                }
            }

            foreach (self::UNSAFE_PATH_PREFIXES as $prefix) {
                if ($prefix === '.env' && $path === '.env') {
                    $unsafePathViolations[] = ['path' => $path, 'reason' => 'unsafe_prefix:.env'];
                    continue;
                }
                if (str_contains($path, $prefix)) {
                    $unsafePathViolations[] = ['path' => $path, 'reason' => 'unsafe_prefix:'.$prefix];
                    break;
                }
            }

            $allowedHit = $this->matchesAny($path, $allowed) || $this->matchesAny($path, $scopeIn);
            if ($allowedHit) {
                $inScope[] = $path;
            } else {
                $outOfScope[] = [
                    'path' => $path,
                    'change_kind' => (string) ($file['change_kind'] ?? 'modified'),
                ];
            }
        }

        $crossAxisViolations = $this->uniqueRows($crossAxisViolations, ['axis', 'path']);
        $forbiddenViolations = $this->uniqueRows($forbiddenViolations, ['path']);
        $unsafePathViolations = $this->uniqueRows($unsafePathViolations, ['path', 'reason']);

        $violationCount = count($forbiddenViolations) + count($crossAxisViolations) + count($unsafePathViolations) + count($outOfScope);
        $status = $violationCount === 0 ? 'agent_merge_review_scope_verified' : 'agent_merge_review_scope_violations_present';

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'verification' => [
                'in_scope_paths' => array_values(array_unique($inScope)),
                'out_of_scope_paths' => $outOfScope,
                'forbidden_violations' => $forbiddenViolations,
                'cross_axis_violations' => $crossAxisViolations,
                'unsafe_path_violations' => $unsafePathViolations,
                'in_scope_count' => count(array_unique($inScope)),
                'out_of_scope_count' => count($outOfScope),
                'forbidden_violation_count' => count($forbiddenViolations),
                'cross_axis_violation_count' => count($crossAxisViolations),
                'unsafe_path_violation_count' => count($unsafePathViolations),
                'violation_count' => $violationCount,
                'all_in_scope' => $violationCount === 0,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['verification_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $clean[] = (string) $path;
        }
        $clean = array_values(array_filter(array_unique($clean), static fn ($p) => $p !== ''));
        sort($clean);

        return $clean;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $path) {
                return true;
            }
            if (str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '#^'.str_replace(['\\*\\*', '\\*'], ['.*', '[^/]*'], preg_quote($pattern, '#')).'$#';
                if (preg_match($regex, $path) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function uniqueRows(array $rows, array $keys): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $signature = implode('|', array_map(static fn ($k) => (string) ($row[$k] ?? ''), $keys));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $row;
        }

        return $out;
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
