<?php

namespace App\Console\Commands;

use App\Services\Ai\Memory\MemoryEntrySafetySummary;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasMemoryListCommand extends Command
{
    protected $signature = 'atlas:memory:list
        {--type=* : Filter by memory type}
        {--scope-type= : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--source-type= : Source type}
        {--privacy= : normal, private, sensitive or secret}
        {--privacy-class= : Alias for --privacy}
        {--status= : active, inactive or archived}
        {--never-recalled : Only entries never returned by atlas:memory:recall}
        {--thin : Only entries whose summary repeats the title}
        {--include-inactive : Include inactive and archived memories}
        {--compact : Omit full memory bodies from JSON output}
        {--limit=50 : Maximum entries}
        {--json : Print machine-readable JSON}';

    protected $description = 'List Atlas central memory registry entries.';

    public function handle(AtlasMemoryRegistryService $memory, MemoryQueryInput $input): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $filters = [
            'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'source_type' => $this->stringOption('source-type'),
            'privacy_class' => $this->stringOption('privacy-class') ?: $this->stringOption('privacy'),
            'status' => $this->stringOption('status'),
            'never_recalled' => (bool) $this->option('never-recalled'),
            'thin' => (bool) $this->option('thin'),
            'include_inactive' => (bool) $this->option('include-inactive'),
        ];

        $compact = (bool) $this->option('compact');
        $includeBodyExcerpt = $compact && (bool) $this->option('thin');
        $limit = $input->registryLimit($this->option('limit'));

        $entries = $memory->search($filters, $limit);
        $rows = $entries->map(fn (AtlasMemoryEntry $entry): array => $this->row($entry, $compact, $includeBodyExcerpt))->values()->all();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'summary' => [
                    'count' => count($rows),
                    'limit' => $limit,
                    'possibly_truncated' => count($rows) >= $limit,
                    'filters' => [
                        'never_recalled' => (bool) $this->option('never-recalled'),
                        'thin' => (bool) $this->option('thin'),
                        'compact' => $compact,
                    ],
                ],
                'memories' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'scope', 'priority', 'privacy', 'redaction', 'source', 'status', 'summary'],
            collect($rows)->map(fn (array $row): array => [
                $row['id'],
                $row['memory_type'],
                $row['scope'],
                $row['priority'],
                $row['privacy_class'],
                $row['redaction_status'],
                $row['source'],
                $row['status'],
                Str::limit((string) ($row['summary'] ?: $row['body']), 90),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function row(AtlasMemoryEntry $entry, bool $compact = false, bool $includeBodyExcerpt = false): array
    {
        $row = [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'scope' => $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type,
            'priority' => $entry->priority,
            'importance' => $entry->importance,
            'privacy_class' => $entry->privacy_class,
            'external_ai_allowed' => $entry->external_ai_allowed,
            'redaction_status' => $entry->redaction_status,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source' => $entry->source_id ? $entry->source_type.':'.$entry->source_id : $entry->source_type,
            'status' => $entry->status,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'safety' => $this->safetySummary($entry),
            'recorded_at' => $entry->recorded_at?->toJSON(),
            'last_used_at' => $entry->last_used_at?->toJSON(),
        ];

        if ($includeBodyExcerpt) {
            $row['body_excerpt'] = Str::limit((string) ($entry->body ?? ''), 220);
        }

        if (! $compact) {
            $row['body'] = $entry->body;
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(AtlasMemoryEntry $entry): array
    {
        return MemoryEntrySafetySummary::forEntry($entry);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

}
