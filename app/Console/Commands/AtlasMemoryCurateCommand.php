<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;

class AtlasMemoryCurateCommand extends Command
{
    protected $signature = 'atlas:memory:curate
        {id : Atlas memory entry id}
        {--summary= : Reviewed summary}
        {--archive : Archive this memory}
        {--note= : Review note}
        {--json : Print machine-readable JSON}';

    protected $description = 'Curate an Atlas memory entry summary or archive a low-value entry.';

    public function handle(AtlasMemoryRegistryService $registry): int
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
        $archive = (bool) $this->option('archive');
        if ($summary === null && ! $archive) {
            $this->error('Informe --summary ou --archive.');

            return self::FAILURE;
        }

        $attributes = [
            'summary' => $summary ?? $entry->summary,
            'curation_note' => $this->stringOption('note'),
        ];
        if ($archive) {
            $attributes['status'] = 'archived';
            $attributes['archived_at'] = now();
        }

        $curated = $registry->curate($entry, $attributes);

        return $this->print(['ok' => true, 'memory' => $this->row($curated)]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function print(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
