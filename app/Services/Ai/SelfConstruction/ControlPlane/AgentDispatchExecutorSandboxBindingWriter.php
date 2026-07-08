<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure, provider-safe writer for provider workspace bindings.
 *
 * A binding locks an executor to a specific workspace with an allowed_files
 * scope, a lease identity, and a rollback path before the executor can touch
 * the workspace. Bindings are idempotent: rebinding the same provider/workspace/
 * scope returns the existing binding without creating a duplicate.
 *
 * Rejection codes (stable, machine-readable):
 *   missing_allowed_files   — allowed_files is empty or not an array
 *   missing_lease_id        — lease_id is empty
 *   missing_rollback_path   — rollback_path is empty
 *   missing_provider_id    — provider_id is empty
 *   missing_workspace_id   — workspace_id is empty
 *
 * Pure: no I/O, no side effects. Callers persist the returned binding.
 */
final class AgentDispatchExecutorSandboxBindingWriter
{
    public const SCHEMA = 'atlas.self_construction.agent_dispatch_executor_sandbox_binding.v1';

    public const STATUS_BOUND = 'bound';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_IDEMPOTENT = 'idempotent';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function bind(array $input): array
    {
        $providerId = trim((string) ($input['provider_id'] ?? ''));
        $workspaceId = trim((string) ($input['workspace_id'] ?? ''));
        $leaseId = trim((string) ($input['lease_id'] ?? ''));
        $rollbackPath = trim((string) ($input['rollback_path'] ?? ''));
        $allowedFiles = is_array($input['allowed_files'] ?? null) ? $input['allowed_files'] : [];

        // Validate required fields — stable blocker codes.
        $blockers = [];
        if ($providerId === '') {
            $blockers[] = 'missing_provider_id';
        }
        if ($workspaceId === '') {
            $blockers[] = 'missing_workspace_id';
        }
        if ($leaseId === '') {
            $blockers[] = 'missing_lease_id';
        }
        if ($rollbackPath === '') {
            $blockers[] = 'missing_rollback_path';
        }
        if ($allowedFiles === []) {
            $blockers[] = 'missing_allowed_files';
        }

        if ($blockers !== []) {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_REJECTED,
                'blockers' => $blockers,
                'binding' => null,
            ];
        }

        // Compute scope_hash from allowed_files (deterministic, sorted).
        $sortedFiles = array_values(array_unique($allowedFiles));
        sort($sortedFiles);
        $scopeHash = hash('sha256', implode("\n", $sortedFiles));

        // Idempotency key: same provider + workspace + scope = same binding.
        $idempotencyKey = $providerId.':'.$workspaceId.':'.$scopeHash;

        $binding = [
            'binding_id' => 'binding-'.substr($scopeHash, 0, 16),
            'provider_id' => $providerId,
            'workspace_id' => $workspaceId,
            'lease_id' => $leaseId,
            'rollback_path' => $rollbackPath,
            'allowed_files' => $sortedFiles,
            'scope_hash' => $scopeHash,
            'idempotency_key' => $idempotencyKey,
            // No raw secrets — only structural identifiers.
        ];

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_BOUND,
            'blockers' => [],
            'binding' => $binding,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * Check if a new binding request is idempotent with an existing binding.
     *
     * @param  array<string,mixed>  $existingBinding
     * @param  array<string,mixed>  $newInput
     */
    public function isIdempotent(array $existingBinding, array $newInput): bool
    {
        $newResult = $this->bind($newInput);
        if ($newResult['binding'] === null) {
            return false;
        }

        return $existingBinding['idempotency_key'] === $newResult['binding']['idempotency_key'];
    }
}
