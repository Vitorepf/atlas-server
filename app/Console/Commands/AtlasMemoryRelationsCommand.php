<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use App\Services\Ai\Memory\MemoryQueryInput;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Commands\Concerns\ParsesKeyValueMetadataOption;

class AtlasMemoryRelationsCommand extends Command
{
    use ParsesKeyValueMetadataOption;

    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    private ?MemoryQueryInput $memoryInput = null;

    protected $signature = 'atlas:memory:relations
        {action=list : list, review, resolve, dismiss, link or supersede}
        {relation? : Relation UUID for review/resolve/dismiss}
        {--id= : Relation UUID for review/resolve/dismiss}
        {--type=* : duplicate, conflict, related, compatible, scoped or supersedes}
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
            'link' => $this->link($governance),
            'supersede' => $this->supersede($governance),
            default => $this->invalidAction($action),
        };
    }

    /**
     * D3 (Obra #18) — reactivate the 0-use command as a WRITE surface: create a
     * relation between two memories so the graph stops being empty. Both endpoints
     * must exist; the type is one of {@see AtlasMemoryEntryRelation::TYPES}.
     */
    private function link(AtlasMemoryGovernanceService $governance): int
    {
        [$source, $target] = $this->relationEndpoints();
        if ($source === null || $target === null) {
            return self::FAILURE;
        }
        $types = array_values(array_filter((array) $this->option('type'), 'is_string'));
        $type = $types[0] ?? 'duplicate';
        if (! in_array($type, AtlasMemoryEntryRelation::TYPES, true)) {
            $this->error('--type deve ser um de: '.implode(', ', AtlasMemoryEntryRelation::TYPES));

            return self::FAILURE;
        }

        $reason = $this->stringOption('reason');
        if ($this->isKnowledgeRelationType($type)) {
            if ($reason === null || $reason === '') {
                $this->error('--reason é obrigatório para tipos de conhecimento: '
                    .implode(', ', AtlasMemoryConflictResolutionService::KNOWLEDGE_RELATION_TYPES));

                return self::FAILURE;
            }
            $status = 'resolved';
        } else {
            $status = $this->stringOption('status') ?: 'open';
        }

        $relation = AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $source->id,
            'target_memory_entry_id' => $target->id,
            'relation_type' => $type,
            'status' => $status,
            'reason' => $reason,
            'metadata' => $this->metadata(),
        ]);

        return $this->outputPayload(['relation' => $governance->relationPayload($relation)]);
    }

    /**
     * D3 — explicit supersede: mark the SOURCE memory as superseded BY the target
     * (source = old, target = new). Sets `superseded_by_id` on the source AND records
     * a conflict-typed relation so the superseded chain is real + consultable.
     */
    private function supersede(AtlasMemoryGovernanceService $governance): int
    {
        [$source, $target] = $this->relationEndpoints();
        if ($source === null || $target === null) {
            return self::FAILURE;
        }

        $source->forceFill(['superseded_by_id' => $target->id])->save();
        $relation = AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $source->id,
            'target_memory_entry_id' => $target->id,
            'relation_type' => AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES,
            'status' => 'resolved',
            'reason' => $this->stringOption('reason') ?: 'superseded_by',
            'metadata' => $this->metadata() + ['verb' => 'supersede'],
        ]);

        return $this->outputPayload([
            'relation' => $governance->relationPayload($relation),
            'superseded' => ['source' => $source->id, 'superseded_by' => $target->id],
        ]);
    }

    /**
     * Resolve --source-id and --target-id to existing entries (both required).
     *
     * @return array{0:?AtlasMemoryEntry,1:?AtlasMemoryEntry}
     */
    private function relationEndpoints(): array
    {
        $sourceId = $this->stringOption('source-id');
        $targetId = $this->stringOption('target-id');
        if ($sourceId === null || $targetId === null) {
            $this->error('Informe --source-id e --target-id.');

            return [null, null];
        }
        $source = AtlasMemoryEntry::query()->find($sourceId);
        $target = AtlasMemoryEntry::query()->find($targetId);
        if ($source === null || $target === null) {
            $this->error('source ou target não encontrado.');

            return [null, null];
        }

        return [$source, $target];
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
            $this->line($this->encode($payload));

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


    private function isKnowledgeRelationType(string $type): bool
    {
        return in_array($type, AtlasMemoryConflictResolutionService::KNOWLEDGE_RELATION_TYPES, true);
    }

    private function memoryInput(): MemoryQueryInput
    {
        return $this->memoryInput ?? app(MemoryQueryInput::class);
    }
}
