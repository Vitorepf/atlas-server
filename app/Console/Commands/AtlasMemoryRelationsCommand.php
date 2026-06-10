<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\AtlasMemoryGovernanceService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasMemoryRelationsCommand extends Command
{
    private ?MemoryQueryInput $memoryInput = null;

    protected $signature = 'atlas:memory:relations
        {action=list : list, review, resolve or dismiss}
        {relation? : Relation UUID for review/resolve/dismiss}
        {--id= : Relation UUID for review/resolve/dismiss}
        {--type=* : duplicate or conflict}
        {--status= : open, resolved or dismissed}
        {--source-id= : Source memory entry UUID}
        {--target-id= : Target memory entry UUID}
        {--source-status= : active, inactive or archived}
        {--target-status= : active, inactive or archived}
        {--resolution-action= : Short resolution action}
        {--reviewed-by=atlas-cli : Reviewer identifier}
        {--note= : Review note}
        {--reason= : Updated relation reason}
        {--metadata=* : Metadata key=value pairs}
        {--limit=50 : Maximum relations when listing}
        {--json : Print machine-readable JSON}';

    protected $description = 'List and review Atlas memory duplicate/conflict relations.';

    public function handle(AtlasMemoryGovernanceService $governance, MemoryQueryInput $input): int
    {
        $this->memoryInput = $input;

        if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            $this->error('Tabela atlas_memory_entry_relations ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = trim(strtolower((string) $this->argument('action')));

        return match ($action) {
            'list' => $this->list($governance),
            'review' => $this->review($governance, null),
            'resolve' => $this->review($governance, 'resolved'),
            'dismiss' => $this->review($governance, 'dismissed'),
            default => $this->invalidAction($action),
        };
    }

    private function list(AtlasMemoryGovernanceService $governance): int
    {
        $relations = $governance->listRelations($this->filters(), $this->memoryInput()->relationLimit($this->option('limit')));

        return $this->outputPayload([
            'relations' => $relations
                ->map(fn (AtlasMemoryEntryRelation $relation): array => $governance->relationPayload($relation))
                ->values()
                ->all(),
        ]);
    }

    private function review(AtlasMemoryGovernanceService $governance, ?string $forcedStatus): int
    {
        $relation = $this->relationById();
        if (! $relation) {
            return self::FAILURE;
        }

        $status = $forcedStatus ?: $this->stringOption('status');
        if (! $status) {
            $this->error('Informe --status=open|resolved|dismissed ou use action resolve/dismiss.');

            return self::FAILURE;
        }

        $relation = $governance->reviewRelation($relation, [
            'status' => $status,
            'source_status' => $this->stringOption('source-status'),
            'target_status' => $this->stringOption('target-status'),
            'resolution_action' => $this->stringOption('resolution-action') ?: ($forcedStatus ? 'cli_'.$forcedStatus : 'cli_review'),
            'reviewed_by' => $this->stringOption('reviewed-by') ?: 'atlas-cli',
            'review_note' => $this->stringOption('note'),
            'reason' => $this->stringOption('reason'),
            'metadata' => $this->metadata(),
        ]);

        return $this->outputPayload([
            'relation' => $governance->relationPayload($relation),
        ]);
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida para atlas:memory:relations: {$action}");

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

        if (isset($payload['relation']) && is_array($payload['relation'])) {
            $relation = $payload['relation'];
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Relation</>', (string) ($relation['id'] ?? '-'));
            $this->table(['field', 'value'], [
                ['type', (string) ($relation['relation_type'] ?? '-')],
                ['status', (string) ($relation['status'] ?? '-')],
                ['source', (string) ($relation['source_memory_entry_id'] ?? '-')],
                ['target', (string) ($relation['target_memory_entry_id'] ?? '-')],
                ['reason', Str::limit((string) ($relation['reason'] ?? '-'), 120)],
            ]);

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'status', 'source', 'target', 'reason'],
            collect((array) ($payload['relations'] ?? []))->map(fn (array $row): array => [
                $row['id'] ?? '-',
                $row['relation_type'] ?? '-',
                $row['status'] ?? '-',
                $row['source_memory_entry_id'] ?? '-',
                $row['target_memory_entry_id'] ?? '-',
                Str::limit((string) ($row['reason'] ?? '-'), 90),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function relationById(): ?AtlasMemoryEntryRelation
    {
        $id = $this->stringOption('id');
        if (! $id) {
            $argument = $this->argument('relation');
            $id = is_string($argument) && trim($argument) !== '' ? trim($argument) : null;
        }

        if (! $id) {
            $this->error('Informe o id da relacao de memoria.');

            return null;
        }

        $relation = AtlasMemoryEntryRelation::query()->find($id);
        if (! $relation) {
            $this->error('Relacao de memoria nao encontrada: '.$id);

            return null;
        }

        return $relation;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
            'status' => $this->stringOption('status'),
            'source_memory_entry_id' => $this->stringOption('source-id'),
            'target_memory_entry_id' => $this->stringOption('target-id'),
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
