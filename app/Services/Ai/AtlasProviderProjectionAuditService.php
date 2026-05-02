<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryProviderProjectionAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasProviderProjectionAuditService
{
    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $metadata
     */
    public function recordApply(array $result, array $context = [], array $metadata = []): ?AtlasMemoryProviderProjectionAudit
    {
        if (! Schema::hasTable('atlas_memory_provider_projection_audits')) {
            return null;
        }

        $review = (array) ($result['review'] ?? []);

        return AtlasMemoryProviderProjectionAudit::query()->create([
            'action' => 'apply',
            'target' => (string) ($result['target'] ?? $metadata['target'] ?? 'all'),
            'workspace' => $this->stringOrNull($result['workspace'] ?? $review['workspace'] ?? $context['workspace'] ?? null),
            'initiator' => (string) ($metadata['initiator'] ?? 'system'),
            'confirmation_mode' => $this->stringOrNull($metadata['confirmation_mode'] ?? $result['confirmation_mode'] ?? null),
            'status' => (string) ($result['status'] ?? 'needs_review'),
            'ok' => (bool) ($result['ok'] ?? false),
            'summary_json' => (array) ($result['summary'] ?? []),
            'applied_json' => $this->sanitizeProjections((array) ($result['applied'] ?? [])),
            'blocked_json' => $this->sanitizeProjections((array) ($result['blocked'] ?? [])),
            'failed_json' => $this->sanitizeProjections((array) ($result['failed'] ?? [])),
            'review_summary_json' => (array) ($review['summary'] ?? []),
            'metadata' => array_merge([
                'created_by' => 'atlas_provider_projection_audit_service',
            ], $metadata),
            'applied_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryProviderProjectionAudit>
     */
    public function search(array $filters = [], int $limit = 50): Collection
    {
        if (! Schema::hasTable('atlas_memory_provider_projection_audits')) {
            return collect();
        }

        return $this->query($filters)
            ->latest('applied_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function summary(array $filters = [], int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $since = now()->subDays($days);

        if (! Schema::hasTable('atlas_memory_provider_projection_audits')) {
            return [
                'ok' => true,
                'period_days' => $days,
                'since_at' => $since->toJSON(),
                'generated_at' => now()->toJSON(),
                'total' => 0,
                'applied' => 0,
                'blocked' => 0,
                'by_target' => [],
                'by_initiator' => [],
                'latest_at' => null,
                'oldest_at' => null,
            ];
        }

        $audits = $this->query($filters)
            ->where('applied_at', '>=', $since)
            ->orderBy('applied_at')
            ->get();
        $oldest = $audits->first();
        $latest = $audits->last();

        return [
            'ok' => true,
            'period_days' => $days,
            'since_at' => $since->toJSON(),
            'generated_at' => now()->toJSON(),
            'total' => $audits->count(),
            'applied' => $audits->where('ok', true)->count(),
            'blocked' => $audits->where('ok', false)->count(),
            'by_target' => $this->groupCounts($audits, 'target'),
            'by_initiator' => $this->groupCounts($audits, 'initiator'),
            'latest_at' => $latest?->applied_at?->toJSON(),
            'oldest_at' => $oldest?->applied_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function purge(array $filters = [], int $olderThanDays = 90, bool $dryRun = true): array
    {
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $cutoff = now()->subDays($olderThanDays);

        if (! Schema::hasTable('atlas_memory_provider_projection_audits')) {
            $confirmationFingerprint = $this->purgeConfirmationFingerprint($filters, $olderThanDays, 0);

            return [
                'ok' => true,
                'dry_run' => $dryRun,
                'older_than_days' => $olderThanDays,
                'cutoff_at' => $cutoff->toJSON(),
                'matched' => 0,
                'deleted' => 0,
                'confirmation_fingerprint' => $confirmationFingerprint,
                'filters' => $this->auditFilters($filters),
            ];
        }

        $query = $this->query($filters)->where('applied_at', '<', $cutoff);
        $matched = (clone $query)->count();
        $confirmationFingerprint = $this->purgeConfirmationFingerprint($filters, $olderThanDays, $matched);
        if (! $dryRun && ! hash_equals($confirmationFingerprint, (string) ($filters['confirmation_fingerprint'] ?? ''))) {
            return [
                'ok' => false,
                'status' => 'confirmation_fingerprint_mismatch',
                'dry_run' => false,
                'older_than_days' => $olderThanDays,
                'cutoff_at' => $cutoff->toJSON(),
                'matched' => $matched,
                'deleted' => 0,
                'confirmation_fingerprint' => $confirmationFingerprint,
                'filters' => $this->auditFilters($filters),
            ];
        }

        $deleted = $dryRun ? 0 : $query->delete();

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'older_than_days' => $olderThanDays,
            'cutoff_at' => $cutoff->toJSON(),
            'matched' => $matched,
            'deleted' => $deleted,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'filters' => $this->auditFilters($filters),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(AtlasMemoryProviderProjectionAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'action' => $audit->action,
            'target' => $audit->target,
            'workspace' => $audit->workspace,
            'initiator' => $audit->initiator,
            'confirmation_mode' => $audit->confirmation_mode,
            'status' => $audit->status,
            'ok' => $audit->ok,
            'summary' => $audit->summary_json ?? [],
            'applied' => $audit->applied_json ?? [],
            'blocked' => $audit->blocked_json ?? [],
            'failed' => $audit->failed_json ?? [],
            'review_summary' => $audit->review_summary_json ?? [],
            'metadata' => $audit->metadata ?? [],
            'applied_at' => $audit->applied_at?->toJSON(),
            'created_at' => $audit->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<int,mixed>  $projections
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeProjections(array $projections): array
    {
        return collect($projections)
            ->filter(fn (mixed $projection): bool => is_array($projection))
            ->map(fn (array $projection): array => [
                'target' => $projection['target'] ?? null,
                'change_type' => $projection['change_type'] ?? null,
                'path' => $projection['path'] ?? null,
                'written' => isset($projection['written']) ? (bool) $projection['written'] : null,
                'error' => $projection['error'] ?? null,
                'reason' => $projection['reason'] ?? null,
                'memory_count' => $projection['memory_count'] ?? data_get($projection, 'result.memory_count'),
                'diff_line_count' => $projection['diff_line_count'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function query(array $filters)
    {
        return AtlasMemoryProviderProjectionAudit::query()
            ->when($this->stringOrNull($filters['workspace'] ?? null), fn ($query, string $workspace) => $query->where('workspace', $workspace))
            ->when($this->stringOrNull($filters['target'] ?? null), fn ($query, string $target) => $query->where('target', $target))
            ->when($this->stringOrNull($filters['status'] ?? null), fn ($query, string $status) => $query->where('status', $status))
            ->when($this->stringOrNull($filters['initiator'] ?? null), fn ($query, string $initiator) => $query->where('initiator', $initiator))
            ->when(
                array_key_exists('ok', $filters) && $filters['ok'] !== null,
                fn ($query) => $query->where('ok', (bool) $filters['ok']),
            );
    }

    /**
     * @param  Collection<int,AtlasMemoryProviderProjectionAudit>  $audits
     * @return array<string,array<string,int|string>>
     */
    private function groupCounts(Collection $audits, string $key): array
    {
        return $audits
            ->groupBy(fn (AtlasMemoryProviderProjectionAudit $audit): string => (string) ($audit->{$key} ?: 'unknown'))
            ->map(fn (Collection $group, string $value): array => [
                'value' => $value,
                'total' => $group->count(),
                'applied' => $group->where('ok', true)->count(),
                'blocked' => $group->where('ok', false)->count(),
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function auditFilters(array $filters): array
    {
        return array_filter([
            'workspace' => $this->stringOrNull($filters['workspace'] ?? null),
            'target' => $this->stringOrNull($filters['target'] ?? null),
            'status' => $this->stringOrNull($filters['status'] ?? null),
            'initiator' => $this->stringOrNull($filters['initiator'] ?? null),
            'ok' => array_key_exists('ok', $filters) ? (bool) $filters['ok'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function purgeConfirmationFingerprint(array $filters, int $olderThanDays, int $matched): string
    {
        return hash('sha256', json_encode([
            'filters' => $this->auditFilters($filters),
            'older_than_days' => $olderThanDays,
            'matched' => $matched,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
