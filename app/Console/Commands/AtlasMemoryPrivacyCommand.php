<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasMemoryPrivacyCommand extends Command
{
    private ?MemoryQueryInput $memoryInput = null;

    protected $signature = 'atlas:memory:privacy
        {action=scan : scan, apply or review}
        {memory? : Memory entry UUID for review}
        {--id= : Memory entry UUID for review}
        {--type=* : Filter by memory type}
        {--scope-type= : global, project, task, engineering_run, workspace, user or session}
        {--scope-id= : Scope identifier}
        {--project-id= : Project UUID}
        {--task-id= : Task UUID}
        {--run-id= : Engineering run UUID}
        {--source-type= : Source type}
        {--privacy= : normal, private, sensitive or secret}
        {--privacy-class= : Alias for --privacy}
        {--allow-external-ai : Allow provider/context-pack use when privacy class permits it}
        {--block-external-ai : Block provider/context-pack use}
        {--redacted-title= : Reviewed provider-safe title}
        {--redacted-body= : Reviewed provider-safe body}
        {--redacted-summary= : Reviewed provider-safe summary}
        {--reviewed-by=atlas-cli : Reviewer identifier}
        {--note= : Review note}
        {--metadata=* : Metadata key=value pairs}
        {--limit=200 : Maximum entries to scan/apply}
        {--json : Print machine-readable JSON}';

    protected $description = 'Apply and review privacy/redaction policy for Atlas memory registry entries.';

    public function handle(AtlasMemoryPrivacyService $privacy, MemoryQueryInput $input): int
    {
        $this->memoryInput = $input;

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = trim(strtolower((string) $this->argument('action')));

        return match ($action) {
            'scan' => $this->scan($privacy, true),
            'apply' => $this->shouldApplySingleReview() ? $this->review($privacy) : $this->scan($privacy, false),
            'review' => $this->review($privacy),
            default => $this->invalidAction($action),
        };
    }

    private function scan(AtlasMemoryPrivacyService $privacy, bool $dryRun): int
    {
        return $this->outputPayload([
            'privacy' => $privacy->scan($this->filters(), $this->memoryInput()->governanceScanLimit($this->option('limit')), $dryRun),
        ]);
    }

    private function review(AtlasMemoryPrivacyService $privacy): int
    {
        $entry = $this->memoryById();
        if (! $entry) {
            return self::FAILURE;
        }

        $entry = $privacy->apply($entry, $this->reviewData());

        return $this->outputPayload([
            'memory' => $this->row($entry),
        ]);
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida para atlas:memory:privacy: {$action}");

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

        if (isset($payload['memory']) && is_array($payload['memory'])) {
            $memory = $payload['memory'];
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Privacy</>', (string) ($memory['id'] ?? '-'));
            $this->table(['field', 'value'], [
                ['privacy', (string) ($memory['privacy_class'] ?? '-')],
                ['external_ai_allowed', ((bool) ($memory['external_ai_allowed'] ?? false)) ? 'yes' : 'no'],
                ['redaction', (string) ($memory['redaction_status'] ?? '-')],
                ['summary', Str::limit((string) ($memory['redacted_summary'] ?? $memory['summary'] ?? ''), 120)],
            ]);

            return self::SUCCESS;
        }

        $scan = (array) ($payload['privacy'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Privacy</>', ((bool) data_get($scan, 'entries.0.dry_run', true)) ? 'scan' : 'apply');
        $this->components->twoColumnDetail('Scanned', (string) ($scan['scanned'] ?? 0));
        $this->components->twoColumnDetail('Updated', (string) ($scan['updated'] ?? 0));
        $this->table(
            ['memory', 'changed', 'privacy', 'external_ai', 'redaction'],
            collect((array) ($scan['entries'] ?? []))->map(fn (array $row): array => [
                $row['memory_entry_id'] ?? '-',
                ((bool) ($row['changed'] ?? false)) ? 'yes' : 'no',
                data_get($row, 'after.privacy_class', '-'),
                data_get($row, 'after.external_ai_allowed') ? 'yes' : 'no',
                data_get($row, 'after.redaction_status', '-'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function memoryById(): ?AtlasMemoryEntry
    {
        $id = $this->stringOption('id');
        if (! $id) {
            $argument = $this->argument('memory');
            $id = is_string($argument) && trim($argument) !== '' ? trim($argument) : null;
        }

        if (! $id) {
            $this->error('Informe o id da memoria.');

            return null;
        }

        $entry = AtlasMemoryEntry::query()->find($id);
        if (! $entry) {
            $this->error('Memoria nao encontrada: '.$id);

            return null;
        }

        return $entry;
    }

    private function shouldApplySingleReview(): bool
    {
        $argument = $this->argument('memory');
        if ($this->stringOption('id') || (is_string($argument) && trim($argument) !== '')) {
            return true;
        }

        if ((bool) $this->option('allow-external-ai') || (bool) $this->option('block-external-ai')) {
            return true;
        }

        foreach (['redacted-title', 'redacted-body', 'redacted-summary', 'note'] as $option) {
            if ($this->stringOption($option)) {
                return true;
            }
        }

        return $this->metadata() !== [];
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'project_id' => $this->stringOption('project-id'),
            'task_id' => $this->stringOption('task-id'),
            'engineering_run_id' => $this->stringOption('run-id'),
            'source_type' => $this->stringOption('source-type'),
            'privacy_class' => $this->stringOption('privacy-class') ?: $this->stringOption('privacy'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewData(): array
    {
        $data = [
            'privacy_class' => $this->stringOption('privacy-class') ?: $this->stringOption('privacy'),
            'redacted_title' => $this->stringOption('redacted-title'),
            'redacted_body' => $this->stringOption('redacted-body'),
            'redacted_summary' => $this->stringOption('redacted-summary'),
            'reviewed_by' => $this->stringOption('reviewed-by') ?: 'atlas-cli',
            'review_note' => $this->stringOption('note'),
            'metadata' => $this->metadata(),
        ];

        if ((bool) $this->option('allow-external-ai')) {
            $data['external_ai_allowed'] = true;
        }
        if ((bool) $this->option('block-external-ai')) {
            $data['external_ai_allowed'] = false;
        }

        return array_filter($data, fn (mixed $value): bool => $value !== null);
    }

    private function row(AtlasMemoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'redacted_title' => $entry->redacted_title,
            'redacted_body' => $entry->redacted_body,
            'redacted_summary' => $entry->redacted_summary,
            'privacy_class' => $entry->privacy_class,
            'external_ai_allowed' => $entry->external_ai_allowed,
            'redaction_status' => $entry->redaction_status,
            'privacy_reviewed_at' => $entry->privacy_reviewed_at?->toJSON(),
            'safety' => $this->safetySummary($entry),
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

    private function memoryInput(): MemoryQueryInput
    {
        return $this->memoryInput ?? app(MemoryQueryInput::class);
    }
}
