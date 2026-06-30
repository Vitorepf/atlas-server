<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Atlas-native project-lane admission policy.
 *
 * Given an external project stewardship lane manifest, returns a deterministic verdict before any 24/7
 * project work can be generated for that lane. Validates required fields, normalizes the workspace policy
 * to `shared_local_main_with_scope_lock`, and fails closed when anything load-bearing is missing or unsafe.
 *
 * PURE: no provider calls, no shell, no git, no human approval surfaces.
 */
final class AtlasProjectLaneAdmissionPolicy
{
    public const SCHEMA = 'atlas.multiproject.project_lane_admission.v1';

    public const ISOLATION = 'shared_local_main_with_scope_lock';

    public const REQUIRED_FIELDS = [
        'project_id',
        'repo_root',
        'objective',
        'mainline_branch',
        'allowed_scope_roots',
        'verification_commands',
        'merge_policy',
        'rollback_policy',
        'knowledge_sync_policy',
        // Self-Construction proof floor — must be explicit before any 24/7 autonomous work.
        'context_freshness_command',
        'queue_namespace',
        'receipt_ledger_path',
        'rollback_verification_command',
        'steady_state_owner',
    ];

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function admit(array $manifest): array
    {
        $reasons = [];
        $facts = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $manifest)) {
                $reasons[] = 'missing_required_field:'.$field;
            }
        }

        $projectId = (string) ($manifest['project_id'] ?? '');
        $objective = trim((string) ($manifest['objective'] ?? ''));
        $repoRoot = (string) ($manifest['repo_root'] ?? '');
        $mainline = (string) ($manifest['mainline_branch'] ?? '');
        $allowedScopes = is_array($manifest['allowed_scope_roots'] ?? null) ? array_values($manifest['allowed_scope_roots']) : [];
        $verificationCmds = is_array($manifest['verification_commands'] ?? null) ? array_values($manifest['verification_commands']) : [];
        $mergePolicy = $manifest['merge_policy'] ?? null;
        $rollbackPolicy = $manifest['rollback_policy'] ?? null;
        $knowledgeSync = $manifest['knowledge_sync_policy'] ?? null;

        if ($projectId === '' || ! preg_match('/^[A-Za-z0-9_\-]+$/', $projectId)) {
            $reasons[] = 'invalid_project_id';
        }
        if ($objective === '') {
            $reasons[] = 'objective_empty';
        }
        if (! $this->isSafeRepoRoot($repoRoot)) {
            $reasons[] = 'unsafe_repo_root';
        }
        if ($mainline === '') {
            $reasons[] = 'mainline_branch_empty';
        }
        if ($allowedScopes === []) {
            $reasons[] = 'allowed_scope_roots_empty';
        } elseif ($this->scopesEscape($repoRoot, $allowedScopes)) {
            $reasons[] = 'allowed_scope_roots_escape_repo_root';
        }
        if ($verificationCmds === []) {
            $reasons[] = 'verification_commands_empty';
        }
        if (! is_array($mergePolicy) || ! isset($mergePolicy['mode'])) {
            $reasons[] = 'merge_policy_missing_mode';
        }
        if (! is_array($rollbackPolicy) || ! isset($rollbackPolicy['mode'])) {
            $reasons[] = 'rollback_policy_missing_mode';
        }
        if (! is_array($knowledgeSync) || ! isset($knowledgeSync['mode'])) {
            $reasons[] = 'knowledge_sync_policy_missing_mode';
        }

        // Self-Construction proof floor: each field must be non-empty.
        if (trim((string) ($manifest['context_freshness_command'] ?? '')) === '') {
            $reasons[] = 'context_freshness_command_empty';
        }
        if (trim((string) ($manifest['queue_namespace'] ?? '')) === '') {
            $reasons[] = 'queue_namespace_empty';
        }
        if (trim((string) ($manifest['receipt_ledger_path'] ?? '')) === '') {
            $reasons[] = 'receipt_ledger_path_empty';
        }
        if (trim((string) ($manifest['rollback_verification_command'] ?? '')) === '') {
            $reasons[] = 'rollback_verification_command_empty';
        }
        // steady_state_owner must explicitly declare atlas_server — no vague ownership.
        if (array_key_exists('steady_state_owner', $manifest) && (string) ($manifest['steady_state_owner'] ?? '') !== 'atlas_server') {
            $reasons[] = 'steady_state_owner_must_be_atlas_server';
        }

        // Dependency flags block admission — they are hard stops, not advisory defaults.
        if (! empty($manifest['requires_human'])) {
            $reasons[] = 'human_dependency_blocks_admission';
        }
        if (! empty($manifest['requires_operator'])) {
            $reasons[] = 'operator_dependency_blocks_admission';
        }
        if (! empty($manifest['calls_external_providers'])) {
            $reasons[] = 'external_provider_dependency_blocks_admission';
        }

        $admitted = $reasons === [];

        $facts = [
            'schema_version' => self::SCHEMA,
            'admitted' => $admitted,
            'project_id' => $projectId,
            'blocking_reasons' => array_values($reasons),
            'workspace_policy' => [
                'isolation' => self::ISOLATION,
                'mainline_branch' => $mainline,
                'allowed_scope_roots' => $allowedScopes,
            ],
            'verification_commands' => $verificationCmds,
            'merge_policy' => is_array($mergePolicy) ? $mergePolicy : null,
            'rollback_policy' => is_array($rollbackPolicy) ? $rollbackPolicy : null,
            'knowledge_sync_policy' => is_array($knowledgeSync) ? $knowledgeSync : null,
            'autonomy_defaults' => [
                'requires_human_approval' => false,
                'calls_external_providers' => false,
                'invokes_shell_or_git' => false,
            ],
        ];

        return $facts;
    }

    private function isSafeRepoRoot(string $repoRoot): bool
    {
        $repoRoot = trim($repoRoot);
        if ($repoRoot === '' || $repoRoot[0] !== '/') {
            return false;
        }
        // Forbid path traversal hints and obvious root-of-fs targets.
        if (str_contains($repoRoot, '..') || str_contains($repoRoot, '~') || str_contains($repoRoot, '*')) {
            return false;
        }
        $forbiddenPrefixes = ['/', '/usr', '/etc', '/bin', '/sbin', '/var', '/tmp', '/dev', '/proc', '/sys'];
        foreach ($forbiddenPrefixes as $bad) {
            if ($repoRoot === $bad || rtrim($repoRoot, '/') === $bad) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowedScopes
     */
    private function scopesEscape(string $repoRoot, array $allowedScopes): bool
    {
        $repoRoot = rtrim($repoRoot, '/');
        foreach ($allowedScopes as $scope) {
            $scope = (string) $scope;
            if (str_contains($scope, '..') || ($scope !== '' && $scope[0] === '/' && ! str_starts_with($scope, $repoRoot.'/'))) {
                return true;
            }
        }

        return false;
    }
}
