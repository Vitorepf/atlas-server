<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesKeyValueMetadataOption;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Memory\AtlasVerbatimMemoryService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasMemoryVerbatimCommand extends Command
{
    private ?MemoryQueryInput $memoryInput = null;

    protected $signature = 'atlas:memory:verbatim
        {action=list : list, add, show, review, release, block, redact or archive}
        {value?* : Text for add or id for show/review/release/block/redact/archive}
        {--id= : Verbatim memory id for show/review/release/block/redact/archive}
        {--type= : decision, command, evidence, quote, requirement, review or failure}
        {--scope-type=global : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--trace-id= : AI trace UUID}
        {--user-id= : User identifier}
        {--session-id= : AI session UUID}
        {--title= : Short title}
        {--summary= : Short summary}
        {--privacy= : normal, private, sensitive or secret}
        {--privacy-class= : Alias for --privacy}
        {--allow-external-ai : Explicitly allow provider/context-pack use when privacy class permits it}
        {--block-external-ai : Block provider/context-pack use}
        {--redacted-text= : Reviewed redacted text}
        {--re-redact : Regenerate redaction from the exact stored text}
        {--review-note= : Review note}
        {--reviewed-by= : Reviewer identifier}
        {--source-type=manual : Source type}
        {--source-id= : Source identifier}
        {--source-label= : Human-readable source label}
        {--tag=* : Tag}
        {--metadata=* : Metadata key=value pairs}
        {--status= : Filter by status when listing}
        {--include-inactive : Include inactive and archived memories when listing}
        {--include-verbatim : Include exact verbatim text in output}
        {--no-link-registry : Do not create a linked central memory registry pointer}
        {--limit=50 : Maximum entries when listing}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage exact Atlas verbatim memories with privacy-aware redaction.';

    public function handle(AtlasVerbatimMemoryService $verbatim, MemoryQueryInput $input): int
    {
        $this->memoryInput = $input;

        if (! DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            $this->error('Tabela atlas_verbatim_memories ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = trim(strtolower((string) $this->argument('action')));

        return match ($action) {
            'add' => $this->add($verbatim),
            'show' => $this->show(),
            'review' => $this->review($verbatim, 'review'),
            'release' => $this->review($verbatim, 'release'),
            'block' => $this->review($verbatim, 'block'),
            'redact' => $this->review($verbatim, 'redact'),
            'archive' => $this->archive($verbatim),
            'list' => $this->list($verbatim),
            default => $this->invalidAction($action),
        };
    }

    private function add(AtlasVerbatimMemoryService $verbatim): int
    {
        $text = trim(implode(' ', array_map('strval', (array) $this->argument('value'))));
        if ($text === '') {
            $this->error('Informe o texto verbatim.');

            return self::FAILURE;
        }

        $memory = $verbatim->record([
            'verbatim_type' => $this->stringOption('type') ?: 'decision',
            'scope_type' => $this->stringOption('scope-type') ?: 'global',
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'trace_id' => $this->stringOption('trace-id'),
            'user_id' => $this->stringOption('user-id'),
            'session_id' => $this->stringOption('session-id'),
            'title' => $this->stringOption('title'),
            'verbatim_text' => $text,
            'summary' => $this->stringOption('summary'),
            'privacy_class' => $this->stringOption('privacy-class') ?: ($this->stringOption('privacy') ?: 'normal'),
            'source_type' => $this->stringOption('source-type') ?: 'manual',
            'source_id' => $this->stringOption('source-id'),
            'source_label' => $this->stringOption('source-label'),
            'tags' => array_values(array_filter((array) $this->option('tag'), 'is_string')),
            'metadata' => $this->metadata(),
            'link_registry' => ! (bool) $this->option('no-link-registry'),
        ]);

        return $this->outputPayload(['verbatim_memory' => $this->row($memory)]);
    }

    private function list(AtlasVerbatimMemoryService $verbatim): int
    {
        $memories = $verbatim->search([
            'types' => array_values(array_filter(
                [$this->stringOption('type')],
                fn (mixed $type): bool => is_string($type) && $type !== '',
            )),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'trace_id' => $this->stringOption('trace-id'),
            'session_id' => $this->stringOption('session-id'),
            'user_id' => $this->stringOption('user-id'),
            'source_type' => $this->stringOption('source-type'),
            'privacy_class' => $this->stringOption('privacy-class') ?: $this->stringOption('privacy'),
            'tags' => array_values(array_filter((array) $this->option('tag'), 'is_string')),
            'status' => $this->stringOption('status'),
            'include_inactive' => (bool) $this->option('include-inactive'),
        ], $this->memoryInput()->verbatimLimit($this->option('limit')));

        return $this->outputPayload([
            'verbatim_memories' => $memories->map(fn (AtlasVerbatimMemory $memory): array => $this->row($memory))->values()->all(),
        ]);
    }

    private function show(): int
    {
        $memory = $this->memoryById();
        if (! $memory) {
            return self::FAILURE;
        }

        return $this->outputPayload(['verbatim_memory' => $this->row($memory)]);
    }

    private function archive(AtlasVerbatimMemoryService $verbatim): int
    {
        $memory = $this->memoryById();
        if (! $memory) {
            return self::FAILURE;
        }

        $memory = $verbatim->transition($memory, 'archived', [
            'archived_reason' => 'atlas:memory:verbatim archive',
        ]);

        return $this->outputPayload(['verbatim_memory' => $this->row($memory)]);
    }

    private function review(AtlasVerbatimMemoryService $verbatim, string $action): int
    {
        $memory = $this->memoryById();
        if (! $memory) {
            return self::FAILURE;
        }

        $memory = $verbatim->review($memory, $this->reviewData($action));

        return $this->outputPayload(['verbatim_memory' => $this->row($memory)]);
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida para atlas:memory:verbatim: {$action}");

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function outputPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (isset($payload['verbatim_memory']) && is_array($payload['verbatim_memory'])) {
            $memory = $payload['verbatim_memory'];
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Verbatim Memory</>', (string) ($memory['id'] ?? '-'));
            $this->table(['field', 'value'], [
                ['type', (string) ($memory['verbatim_type'] ?? '-')],
                ['scope', (string) ($memory['scope'] ?? '-')],
                ['privacy', (string) ($memory['privacy_class'] ?? '-')],
                ['redaction', (string) ($memory['redaction_status'] ?? '-')],
                ['external_ai_allowed', ((bool) ($memory['external_ai_allowed'] ?? false)) ? 'yes' : 'no'],
                ['registry', (string) ($memory['memory_entry_id'] ?? '-')],
            ]);

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'scope', 'privacy', 'redaction', 'status', 'summary'],
            collect((array) ($payload['verbatim_memories'] ?? []))->map(fn (array $row): array => [
                $row['id'] ?? '-',
                $row['verbatim_type'] ?? '-',
                $row['scope'] ?? '-',
                $row['privacy_class'] ?? '-',
                $row['redaction_status'] ?? '-',
                $row['status'] ?? '-',
                Str::limit((string) (($row['summary'] ?? null) ?: ($row['redacted_text'] ?? '')), 90),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function memoryById(): ?AtlasVerbatimMemory
    {
        $id = $this->stringOption('id') ?: $this->firstValue();
        if (! $id) {
            $this->error('Informe o id da memoria verbatim.');

            return null;
        }

        $memory = AtlasVerbatimMemory::query()->find($id);
        if (! $memory) {
            $this->error('Memoria verbatim nao encontrada: '.$id);

            return null;
        }

        return $memory;
    }

    private function row(AtlasVerbatimMemory $memory): array
    {
        $includeVerbatim = (bool) $this->option('include-verbatim');

        return [
            'id' => $memory->id,
            'memory_entry_id' => $memory->memory_entry_id,
            'verbatim_type' => $memory->verbatim_type,
            'scope_type' => $memory->scope_type,
            'scope_id' => $memory->scope_id,
            'scope' => $memory->scope_id ? $memory->scope_type.':'.$memory->scope_id : $memory->scope_type,
            'project_id' => $memory->project_id,
            'task_id' => $memory->task_id,
            'engineering_run_id' => $memory->engineering_run_id,
            'trace_id' => $memory->trace_id,
            'session_id' => $memory->session_id,
            'user_id' => $memory->user_id,
            'title' => $memory->title,
            'verbatim_text' => $includeVerbatim ? $memory->verbatim_text : null,
            'redacted_text' => $memory->redacted_text,
            'summary' => $memory->summary,
            'privacy_class' => $memory->privacy_class,
            'external_ai_allowed' => $memory->external_ai_allowed,
            'redaction_status' => $memory->redaction_status,
            'source_type' => $memory->source_type,
            'source_id' => $memory->source_id,
            'status' => $memory->status,
            'tags' => array_values((array) $memory->tags),
            'metadata' => $memory->metadata,
            'safety' => $this->safetySummary($memory, $includeVerbatim),
            'recorded_at' => $memory->recorded_at?->toJSON(),
            'archived_at' => $memory->archived_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(AtlasVerbatimMemory $memory, bool $includeVerbatim): array
    {
        $providerExportAllowed = $memory->external_ai_allowed === true
            && $memory->privacy_class !== 'secret'
            && $memory->redaction_status !== 'blocked'
            && trim((string) $memory->redacted_text) !== '';

        return [
            'schema_version' => 'atlas.verbatim_memory.safety.v1',
            'memory_eligible' => $memory->status === 'active',
            'context_eligible' => $memory->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $memory->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => $includeVerbatim,
            'verbatim_text_exposed' => $includeVerbatim,
            'privacy_class' => $memory->privacy_class,
            'redaction_status' => $memory->redaction_status,
            'content_hash' => $memory->content_hash,
            'redacted_hash' => $memory->redacted_hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewData(string $action): array
    {
        $privacy = $this->stringOption('privacy-class') ?: $this->stringOption('privacy');
        $data = [
            'review_action' => 'cli_'.$action,
            'reviewed_by' => $this->stringOption('reviewed-by') ?: 'atlas-cli',
            'review_note' => $this->stringOption('review-note'),
            'metadata' => $this->metadata(),
        ];

        if ($privacy) {
            $data['privacy_class'] = $privacy;
        }
        if ((bool) $this->option('allow-external-ai')) {
            $data['external_ai_allowed'] = true;
        }
        if ((bool) $this->option('block-external-ai')) {
            $data['external_ai_allowed'] = false;
        }
        if ($this->stringOption('redacted-text') !== null) {
            $data['redacted_text'] = $this->stringOption('redacted-text');
        }
        if ((bool) $this->option('re-redact')) {
            $data['re_redact'] = true;
        }
        if ($this->stringOption('summary') !== null) {
            $data['summary'] = $this->stringOption('summary');
        }

        if ($action === 'release') {
            $data['privacy_class'] = $privacy ?: 'normal';
            $data['external_ai_allowed'] = true;
            $data['re_redact'] ??= true;
            $data['status'] = 'active';
        }
        if ($action === 'block') {
            $data['external_ai_allowed'] = false;
            $data['re_redact'] ??= true;
        }
        if ($action === 'redact') {
            $data['re_redact'] = $this->stringOption('redacted-text') === null;
        }

        return $data;
    }

    /**
     * @return array<string,string>
     */

    private function firstValue(): ?string
    {
        $values = array_values(array_filter((array) $this->argument('value'), 'is_string'));

        return $values[0] ?? null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function memoryInput(): MemoryQueryInput
    {
        return $this->memoryInput ?? app(MemoryQueryInput::class);
    }
}
