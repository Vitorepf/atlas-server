<?php

namespace App\Services\Tools;

use App\Models\AtlasToolPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AtlasToolApprovalService
{
    public function __construct(private readonly AtlasToolRegistryService $registry) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function approve(string $toolSlug, string $workspace, array $options = []): AtlasToolPolicy
    {
        $this->ensureTables();

        if (! $this->registry->definition($toolSlug)) {
            throw new \InvalidArgumentException("Tool [{$toolSlug}] is not registered.");
        }

        $scopeType = $this->scopeType((string) ($options['scope_type'] ?? 'workspace'));
        $scopeId = $scopeType === 'workspace'
            ? hash('sha256', realpath($workspace) ?: $workspace)
            : null;
        $ttlHours = max(1, min(720, (int) ($options['ttl_hours'] ?? 24)));
        $approvedUntil = now()->addHours($ttlHours);

        return AtlasToolPolicy::query()->updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'tool_slug' => $toolSlug,
            ],
            [
                'enabled' => true,
                'failure_policy' => $options['failure_policy'] ?? null,
                'timeout_seconds' => isset($options['timeout_seconds']) && is_numeric($options['timeout_seconds'])
                    ? (int) $options['timeout_seconds']
                    : null,
                'metadata' => [
                    'approved' => true,
                    'approved_at' => now()->toJSON(),
                    'approved_until' => $approvedUntil->toJSON(),
                    'approved_by' => (string) ($options['approved_by'] ?? 'atlas_operator'),
                    'approval_reason' => (string) ($options['reason'] ?? 'operator_approved_tool_execution'),
                    'network_allowed' => (bool) ($options['network_allowed'] ?? false),
                    'max_execution_tier' => $this->executionTier($options['max_execution_tier'] ?? null),
                    'sandbox_mode' => $this->sandboxMode($options['sandbox_mode'] ?? null),
                    'privacy_level' => $this->privacyLevel($options['privacy_level'] ?? null),
                    'task_type' => $this->taskType($options['task_type'] ?? null),
                    'requires_provider_safe' => (bool) ($options['requires_provider_safe'] ?? false),
                    'workspace_hash' => hash('sha256', realpath($workspace) ?: $workspace),
                    'ttl_hours' => $ttlHours,
                    'approval_source' => (string) ($options['source'] ?? 'cli'),
                ],
            ],
        );
    }

    public function revoke(string $toolSlug, string $workspace, string $scopeType = 'workspace'): ?AtlasToolPolicy
    {
        $this->ensureTables();

        $scopeType = $this->scopeType($scopeType);
        $scopeId = $scopeType === 'workspace'
            ? hash('sha256', realpath($workspace) ?: $workspace)
            : null;
        $policy = AtlasToolPolicy::query()
            ->where('scope_type', $scopeType)
            ->where('tool_slug', $toolSlug)
            ->when($scopeId === null, fn ($query) => $query->whereNull('scope_id'), fn ($query) => $query->where('scope_id', $scopeId))
            ->first();

        if (! $policy) {
            return null;
        }

        $metadata = (array) $policy->metadata;
        $policy->forceFill([
            'metadata' => [
                ...$metadata,
                'approved' => false,
                'revoked_at' => now()->toJSON(),
                'revocation_source' => 'operator',
            ],
        ])->save();

        return $policy->refresh();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function policies(string $workspace, int $limit = 100): array
    {
        $this->ensureTables();

        $workspaceHash = hash('sha256', realpath($workspace) ?: $workspace);

        return AtlasToolPolicy::query()
            ->where(function ($query) use ($workspaceHash): void {
                $query->where(function ($query) use ($workspaceHash): void {
                    $query->where('scope_type', 'workspace')->where('scope_id', $workspaceHash);
                })->orWhere(function ($query): void {
                    $query->where('scope_type', 'global')->whereNull('scope_id');
                });
            })
            ->latest('updated_at')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (AtlasToolPolicy $policy): array => [
                ...$policy->toArray(),
                'approval_status' => $this->approvalStatus($policy),
            ])
            ->values()
            ->all();
    }

    public function approvalStatus(AtlasToolPolicy $policy): string
    {
        $metadata = (array) $policy->metadata;
        if (! (bool) ($metadata['approved'] ?? false)) {
            return 'not_approved';
        }

        $approvedUntil = $metadata['approved_until'] ?? null;
        if (! is_string($approvedUntil) || trim($approvedUntil) === '') {
            return 'approved';
        }

        try {
            return Carbon::parse($approvedUntil)->isFuture() ? 'approved' : 'expired';
        } catch (\Throwable) {
            return 'invalid';
        }
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('atlas_tool_policies')) {
            throw new \RuntimeException('Tool runtime tables are not migrated.');
        }
    }

    private function scopeType(string $scopeType): string
    {
        return in_array($scopeType, ['workspace', 'global'], true) ? $scopeType : 'workspace';
    }

    private function executionTier(mixed $tier): string
    {
        $tier = strtoupper(trim((string) ($tier ?: 'T3')));

        return in_array($tier, ['T0', 'T1', 'T2', 'T3'], true) ? $tier : 'T3';
    }

    private function sandboxMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) ($mode ?: 'workspace')));

        return in_array($mode, ['workspace', 'worktree', 'docker', 'host', 'none'], true) ? $mode : 'workspace';
    }

    private function privacyLevel(mixed $level): string
    {
        $level = strtolower(trim((string) ($level ?: 'standard')));

        return in_array($level, ['standard', 'sensitive', 'restricted'], true) ? $level : 'standard';
    }

    private function taskType(mixed $taskType): string
    {
        $taskType = strtolower(trim((string) ($taskType ?: 'manual')));

        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $taskType) === 1 ? $taskType : 'manual';
    }
}
