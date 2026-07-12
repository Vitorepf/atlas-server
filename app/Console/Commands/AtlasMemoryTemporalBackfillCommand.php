<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryTemporalDefaultDeriver;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class AtlasMemoryTemporalBackfillCommand extends Command
{
    protected $signature = 'atlas:memory:temporal-backfill
        {--dry-run : Preview MAXH-02 derived defaults without writing}
        {--apply : Apply MAXH-02 derived defaults to missing fields}
        {--revert : Clear fields derived by MAXH-02 default_type_map}
        {--limit=500 : Maximum rows to scan}
        {--json : Emit machine-readable JSON}';

    protected $description = 'MAXH-02 — derive default temporal truth fields for Atlas Memory entries.';

    public function handle(AtlasMemoryTemporalDefaultDeriver $deriver): int
    {
        $mode = (bool) $this->option('revert')
            ? 'revert'
            : ((bool) $this->option('apply') ? 'apply' : 'dry_run');
        $limit = max(1, min(5000, (int) $this->option('limit')));

        if (! Schema::hasTable('atlas_memory_entries')) {
            return $this->emit([
                'temporal_backfill' => [
                    'schema_version' => 'atlas.memory.temporal_backfill.v1',
                    'slice' => 'MAXH-02',
                    'mode' => $mode,
                    'status' => 'unavailable',
                    'reason' => 'atlas_memory_entries_missing',
                    'candidates' => 0,
                    'applied' => 0,
                ],
            ]);
        }

        $entries = AtlasMemoryEntry::query()
            ->where('status', 'active')
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get();

        if ($mode === 'revert') {
            $reverted = 0;
            foreach ($entries as $entry) {
                if (data_get($entry->metadata, 'temporal_truth.derived_by') !== AtlasMemoryTemporalDefaultDeriver::DERIVED_BY
                    || data_get($entry->metadata, 'temporal_truth.provenance') !== AtlasMemoryTemporalDefaultDeriver::PROVENANCE) {
                    continue;
                }
                $metadata = (array) $entry->metadata;
                data_forget($metadata, 'temporal_truth');
                $entry->forceFill([
                    'authority_level' => null,
                    'observed_at' => null,
                    'stale_after' => null,
                    'metadata' => $metadata,
                ])->save();
                $reverted++;
            }

            return $this->emit([
                'temporal_backfill' => [
                    'schema_version' => 'atlas.memory.temporal_backfill.v1',
                    'slice' => 'MAXH-02',
                    'mode' => 'revert',
                    'status' => 'ok',
                    'candidates' => $reverted,
                    'applied' => 0,
                    'reverted' => $reverted,
                ],
            ]);
        }

        $preview = [];
        $applied = 0;
        foreach ($entries as $entry) {
            $payload = array_merge($entry->getAttributes(), [
                'metadata' => $entry->metadata ?? [],
            ]);
            $withDefaults = $deriver->applyMissing($payload);
            $derived = $this->changedDefaults($payload, $withDefaults);
            if ($derived === []) {
                continue;
            }

            $preview[] = [
                'id' => (string) $entry->id,
                'memory_type' => (string) $entry->memory_type,
                'derived' => $derived,
            ];

            if ($mode === 'apply') {
                $entry->forceFill([
                    'authority_level' => $withDefaults['authority_level'] ?? $entry->authority_level,
                    'observed_at' => $withDefaults['observed_at'] ?? $entry->observed_at,
                    'stale_after' => $withDefaults['stale_after'] ?? $entry->stale_after,
                    'metadata' => $withDefaults['metadata'] ?? $entry->metadata,
                ])->save();
                $applied++;
            }
        }

        return $this->emit([
            'temporal_backfill' => [
                'schema_version' => 'atlas.memory.temporal_backfill.v1',
                'slice' => 'MAXH-02',
                'mode' => $mode,
                'status' => 'ok',
                'candidates' => count($preview),
                'applied' => $applied,
                'preview' => array_slice($preview, 0, 20),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    private function changedDefaults(array $before, array $after): array
    {
        $derived = [];
        foreach (['authority_level', 'observed_at', 'stale_after'] as $field) {
            if (($before[$field] ?? null) === null && ($after[$field] ?? null) !== null) {
                $derived[$field] = $this->serialize($after[$field]);
            }
        }

        return $derived;
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $report = (array) ($payload['temporal_backfill'] ?? []);
        $this->components->twoColumnDetail('Atlas Memory Temporal Backfill', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($report['mode'] ?? 'dry_run'));
        $this->components->twoColumnDetail('Candidates', (string) ($report['candidates'] ?? 0));
        $this->components->twoColumnDetail('Applied', (string) ($report['applied'] ?? 0));

        return self::SUCCESS;
    }
}
