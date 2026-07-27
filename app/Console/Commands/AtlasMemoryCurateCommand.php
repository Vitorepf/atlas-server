<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasMemoryCurateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:curate
        {id : Atlas memory entry id}
        {--summary= : Reviewed summary}
        {--type= : Reclassify memory type}
        {--scope-type= : Re-scope memory to a non-long-horizon scope}
        {--scope-id= : Scope id for non-global scopes}
        {--archive : Archive this memory}
        {--note= : Review note}
        {--json : Print machine-readable JSON}';

    protected $description = 'Curate an Atlas memory entry summary or archive a low-value entry.';

    public function handle(AtlasMemoryRegistryService $registry, AtlasMemoryUsageService $usages): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $entry = AtlasMemoryEntry::query()->find((string) $this->argument('id'));
        if (! $entry) {
            $this->error('Memoria nao encontrada.');

            return self::FAILURE;
        }

        $summary = $this->summaryOption();
        $memoryType = $this->stringOption('type');
        $scopeType = $this->stringOption('scope-type');
        $scopeId = $this->stringOption('scope-id');
        $archive = (bool) $this->option('archive');
        if ($summary === null && $memoryType === null && $scopeType === null && ! $archive) {
            $this->error('Informe --summary, --type, --scope-type ou --archive.');

            return self::FAILURE;
        }
        if ($memoryType !== null && ! in_array($memoryType, AtlasMemoryEntry::TYPES, true)) {
            $this->error('--type deve ser um de: '.implode(', ', AtlasMemoryEntry::TYPES));

            return self::FAILURE;
        }
        if ($scopeType !== null && ! in_array($scopeType, AtlasMemoryEntry::SCOPES, true)) {
            $this->error('--scope-type deve ser um de: '.implode(', ', AtlasMemoryEntry::SCOPES));

            return self::FAILURE;
        }
        if ($scopeType !== null && in_array($scopeType, AtlasMemoryEntry::LONG_HORIZON_SCOPES, true)) {
            $this->error('--scope-type='.$scopeType.' exige LongHorizonMemoryPromotionGuard via delta; curate nao pode bypassar.');

            return self::FAILURE;
        }
        if ($scopeType !== null && $scopeType !== 'global' && $scopeId === null) {
            $this->error('Informe --scope-id para escopos nao-globais.');

            return self::FAILURE;
        }

        $attributes = [
            'summary' => $summary ?? $entry->summary,
            'curation_note' => $this->stringOption('note'),
        ];
        if ($memoryType !== null) {
            $attributes['memory_type'] = $memoryType;
        }
        if ($scopeType !== null) {
            $attributes['scope_type'] = $scopeType;
            $attributes['scope_id'] = $scopeType === 'global' ? null : $scopeId;
        }
        if ($archive) {
            $attributes['status'] = 'archived';
            $attributes['archived_at'] = now();
        }

        $curated = $registry->curate($entry, $attributes);

        if ($archive) {
            // MAXB-05 — MEM-06 curate demote/archive → mined_negative (flag-gated).
            $usages->recordMinedNegative((string) $curated->id, 'curate_demote', [
                'label_kind' => 'retrieval_calibration',
                'content_hash' => $curated->content_hash,
                'source_id' => 'curate:'.$curated->id,
            ]);
        }

        return $this->print(['ok' => true, 'memory' => $this->row($curated)]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function print(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $memory = (array) $payload['memory'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Memoria curada</>', (string) $memory['id']);
        $this->components->twoColumnDetail('Status', (string) $memory['status']);
        $this->components->twoColumnDetail('Summary', (string) $memory['summary']);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(AtlasMemoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'status' => $entry->status,
            'archived_at' => $entry->archived_at?->toJSON(),
            'metadata' => $entry->metadata ?? [],
        ];
    }

    private function summaryOption(): ?string
    {
        $summary = $this->stringOption('summary');

        return $summary !== null ? mb_substr($summary, 0, 240) : null;
    }

}
