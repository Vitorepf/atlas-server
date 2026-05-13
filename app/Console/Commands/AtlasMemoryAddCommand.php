<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AtlasMemoryAddCommand extends Command
{
    protected $signature = 'atlas:memory:add
        {body?* : Memory body}
        {--type=technical_context : Memory type}
        {--scope-type=global : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--user-id= : User identifier}
        {--session-id= : AI session UUID}
        {--title= : Short title}
        {--summary= : Short summary}
        {--importance=3 : Importance from 1 to 5}
        {--priority=50 : Priority from 0 to 100}
        {--confidence= : Confidence from 0 to 1}
        {--privacy=normal : normal, private, sensitive or secret}
        {--privacy-class= : Alias for --privacy}
        {--allow-external-ai : Allow provider/context-pack use when privacy class permits it}
        {--block-external-ai : Block provider/context-pack use}
        {--source-type=manual : Source type}
        {--source-id= : Source identifier}
        {--source-label= : Human-readable source label}
        {--tag=* : Tag}
        {--metadata=* : Metadata key=value pairs}
        {--json : Print machine-readable JSON}';

    protected $description = 'Add an Atlas central memory registry entry.';

    public function handle(AtlasMemoryRegistryService $memory): int
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $body = trim(implode(' ', array_map('strval', (array) $this->argument('body'))));
        if ($body === '') {
            $this->error('Informe o texto da memoria.');

            return self::FAILURE;
        }

        $entry = $memory->record([
            'memory_type' => $this->stringOption('type') ?: 'technical_context',
            'scope_type' => $this->stringOption('scope-type') ?: 'global',
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'user_id' => $this->stringOption('user-id'),
            'session_id' => $this->stringOption('session-id'),
            'title' => $this->stringOption('title'),
            'body' => $body,
            'summary' => $this->stringOption('summary'),
            'importance' => (int) $this->option('importance'),
            'priority' => (int) $this->option('priority'),
            'confidence' => $this->stringOption('confidence'),
            'privacy_class' => $this->stringOption('privacy-class') ?: ($this->stringOption('privacy') ?: 'normal'),
            'external_ai_allowed' => (bool) $this->option('block-external-ai') ? false : ((bool) $this->option('allow-external-ai') ?: null),
            'source_type' => $this->stringOption('source-type') ?: 'manual',
            'source_id' => $this->stringOption('source-id'),
            'source_label' => $this->stringOption('source-label'),
            'tags' => array_values(array_filter((array) $this->option('tag'), 'is_string')),
            'metadata' => $this->metadata(),
        ]);

        $payload = ['memory' => $this->row($entry)];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Memoria registrada: '.$entry->id);
        $this->table(['field', 'value'], [
            ['type', $entry->memory_type],
            ['scope', $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type],
            ['priority', (string) $entry->priority],
            ['source', $entry->source_id ? $entry->source_type.':'.$entry->source_id : $entry->source_type],
        ]);

        return self::SUCCESS;
    }

    private function row(AtlasMemoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $entry->title,
            'body' => $entry->body,
            'summary' => $entry->summary,
            'priority' => $entry->priority,
            'importance' => $entry->importance,
            'privacy_class' => $entry->privacy_class,
            'external_ai_allowed' => $entry->external_ai_allowed,
            'redaction_status' => $entry->redaction_status,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'status' => $entry->status,
            'safety' => $this->safetySummary($entry),
            'recorded_at' => $entry->recorded_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(AtlasMemoryEntry $entry): array
    {
        $providerExportAllowed = $entry->external_ai_allowed === true
            && $entry->privacy_class !== 'secret'
            && $entry->redaction_status !== 'blocked';

        return [
            'schema_version' => 'atlas.memory_entry.safety.v1',
            'memory_eligible' => $entry->status === 'active',
            'context_eligible' => $entry->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $entry->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => $entry->privacy_class,
            'redaction_status' => $entry->redaction_status,
            'content_hash' => $entry->content_hash,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function metadata(): array
    {
        $metadata = [];
        foreach ((array) $this->option('metadata') as $item) {
            if (! is_string($item) || ! str_contains($item, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $item, 2);
            $key = trim($key);
            if ($key !== '') {
                $metadata[$key] = trim($value);
            }
        }

        return $metadata;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
