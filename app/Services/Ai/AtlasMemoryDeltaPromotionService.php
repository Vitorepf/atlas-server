<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionGuard;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AtlasMemoryDeltaPromotionService
{
    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly MemoryQueryInput $input,
        private readonly LongHorizonMemoryPromotionGuard $longHorizonGuard = new LongHorizonMemoryPromotionGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function promote(AiMemoryDelta $delta, array $overrides = []): AtlasMemoryEntry
    {
        $this->assertTables();

        // TEOS-I1 long-horizon scope guard. Throws before the DB transaction
        // so a refused promotion never leaks a row.
        $this->longHorizonGuard->assertPromotable($delta, $overrides);

        return DB::transaction(function () use ($delta, $overrides): AtlasMemoryEntry {
            $delta = AiMemoryDelta::query()->lockForUpdate()->findOrFail($delta->id);
            $force = (bool) ($overrides['force'] ?? false);

            if (! $force && ! in_array($delta->status, ['accepted', 'promoted'], true)) {
                throw new InvalidArgumentException('Somente memory deltas accepted podem ser promovidos sem --force.');
            }

            $existing = $this->existingEntry($delta);
            if ($existing) {
                $this->markPromoted($delta, $existing);

                return $existing;
            }

            $entry = $this->registry->record($this->entryAttributes($delta, $overrides));
            $this->markPromoted($delta, $entry);

            return $entry;
        });
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function promoteAccepted(array $filters = []): Collection
    {
        $query = AiMemoryDelta::query()->where('status', 'accepted');

        if (is_string($filters['scope'] ?? null) && trim((string) $filters['scope']) !== '') {
            $query->where('scope', trim((string) $filters['scope']));
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        return $query
            ->latest('updated_at')
            ->limit($this->input->promotionLimit($filters['limit'] ?? null))
            ->get()
            ->map(fn (AiMemoryDelta $delta): AtlasMemoryEntry => $this->promote($delta, $filters));
    }

    private function assertTables(): void
    {
        if (! Schema::hasTable('ai_memory_deltas')) {
            throw new RuntimeException('Tabela ai_memory_deltas ainda nao existe. Rode migrations.');
        }

        if (! Schema::hasTable('atlas_memory_entries')) {
            throw new RuntimeException('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');
        }
    }

    private function existingEntry(AiMemoryDelta $delta): ?AtlasMemoryEntry
    {
        if (is_string($delta->promoted_memory_entry_id) && $delta->promoted_memory_entry_id !== '') {
            $entry = AtlasMemoryEntry::query()->find($delta->promoted_memory_entry_id);
            if ($entry) {
                return $entry;
            }
        }

        return AtlasMemoryEntry::query()
            ->where('source_type', 'ai_memory_delta')
            ->where('source_id', $delta->id)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function entryAttributes(AiMemoryDelta $delta, array $overrides): array
    {
        $scope = $this->scopeAttributes($delta, $overrides);
        $metadata = array_merge($this->deltaMetadata($delta), (array) ($overrides['metadata'] ?? []));
        if (is_scalar($overrides['promoted_by'] ?? null) && trim((string) $overrides['promoted_by']) !== '') {
            $metadata['promoted_by'] = trim((string) $overrides['promoted_by']);
        }

        return array_merge($scope, [
            'memory_type' => $this->memoryType((string) ($overrides['memory_type'] ?? $delta->type)),
            'trace_id' => $delta->source_trace_id,
            'session_id' => $scope['session_id'] ?? $delta->source_session_id,
            'title' => $overrides['title'] ?? $this->title($delta),
            'body' => $delta->claim,
            'summary' => $overrides['summary'] ?? Str::limit($delta->claim, 280, ''),
            'importance' => (int) ($overrides['importance'] ?? $this->importance($delta)),
            'priority' => (int) ($overrides['priority'] ?? $this->priority($delta)),
            'confidence' => $delta->confidence,
            'source_type' => 'ai_memory_delta',
            'source_id' => $delta->id,
            'source_label' => 'Atlas memory delta',
            'status' => 'active',
            'tags' => array_values(array_unique(array_merge([
                'ai_memory_delta',
                'delta_type:'.$delta->type,
            ], array_values(array_filter((array) ($overrides['tags'] ?? []), 'is_string'))))),
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function scopeAttributes(AiMemoryDelta $delta, array $overrides): array
    {
        if (is_string($overrides['scope_type'] ?? null) && trim((string) $overrides['scope_type']) !== '') {
            return [
                'scope_type' => trim((string) $overrides['scope_type']),
                'scope_id' => isset($overrides['scope_id']) ? trim((string) $overrides['scope_id']) : null,
                'project_id' => $this->uuidOrNull($overrides['project_id'] ?? null),
                'task_id' => $this->uuidOrNull($overrides['task_id'] ?? null),
                'engineering_run_id' => $this->uuidOrNull($overrides['engineering_run_id'] ?? ($overrides['run_id'] ?? null)),
                'session_id' => $this->uuidOrNull($overrides['session_id'] ?? null),
                'user_id' => isset($overrides['user_id']) ? trim((string) $overrides['user_id']) : null,
            ];
        }

        $scope = (string) ($delta->scope ?: 'global');
        if ($scope === 'global' || ! str_contains($scope, ':')) {
            return ['scope_type' => 'global', 'scope_id' => null];
        }

        [$kind, $id] = explode(':', $scope, 2);
        $id = trim($id);

        return match ($kind) {
            'project' => [
                'scope_type' => 'project',
                'scope_id' => Str::limit($id, 120, ''),
                'project_id' => $this->uuidOrNull($id),
            ],
            'task' => [
                'scope_type' => 'task',
                'scope_id' => Str::limit($id, 120, ''),
                'task_id' => $this->uuidOrNull($id),
            ],
            'engineering_run', 'run' => [
                'scope_type' => 'engineering_run',
                'scope_id' => Str::limit($id, 120, ''),
                'engineering_run_id' => $this->uuidOrNull($id),
            ],
            'session' => [
                'scope_type' => 'session',
                'scope_id' => Str::limit($id, 120, ''),
                'session_id' => $this->uuidOrNull($id),
            ],
            'user' => [
                'scope_type' => 'user',
                'scope_id' => Str::limit($id, 120, ''),
                'user_id' => Str::limit($id, 120, ''),
            ],
            'workspace' => [
                'scope_type' => 'workspace',
                'scope_id' => $this->workspaceScopeId($id),
            ],
            default => ['scope_type' => 'global', 'scope_id' => null],
        };
    }

    private function memoryType(string $deltaType): string
    {
        $type = match ($deltaType) {
            'decision' => 'decision',
            'preference' => 'preference',
            'feedback' => 'feedback',
            'error_pattern', 'issue' => 'issue',
            'resolution' => 'resolution',
            'benchmark', 'benchmark_observation' => 'benchmark_observation',
            'harness_learning' => 'harness_learning',
            'anti_memory' => 'anti_memory',
            'strategic_insight' => 'strategic_insight',
            default => 'technical_context',
        };

        return in_array($type, AtlasMemoryEntry::TYPES, true) ? $type : 'technical_context';
    }

    private function title(AiMemoryDelta $delta): string
    {
        return Str::limit(match ($delta->type) {
            'preference' => 'Preferencia promovida',
            'error_pattern' => 'Padrao de erro promovido',
            'decision' => 'Decisao promovida',
            default => 'Memory delta promovido',
        }.': '.$delta->claim, 180, '');
    }

    private function importance(AiMemoryDelta $delta): int
    {
        if ($delta->type === 'error_pattern') {
            return 4;
        }

        return (float) $delta->confidence >= 0.85 ? 4 : 3;
    }

    private function priority(AiMemoryDelta $delta): int
    {
        return max(45, min(90, (int) round(((float) $delta->confidence) * 100)));
    }

    /**
     * @return array<string,mixed>
     */
    private function deltaMetadata(AiMemoryDelta $delta): array
    {
        return [
            'promoted_from' => 'ai_memory_delta',
            'delta_id' => $delta->id,
            'delta_type' => $delta->type,
            'delta_scope' => $delta->scope,
            'source_workspace' => $delta->source_workspace,
            'evidence' => $delta->evidence ?? [],
            'use_when' => $delta->use_when ?? [],
            'do_not_use_when' => $delta->do_not_use_when ?? [],
            'requires_confirmation' => $delta->requires_confirmation,
            'valid_from' => $delta->valid_from?->toJSON(),
            'valid_until' => $delta->valid_until?->toJSON(),
        ];
    }

    private function markPromoted(AiMemoryDelta $delta, AtlasMemoryEntry $entry): void
    {
        $updates = ['status' => 'promoted'];

        if (Schema::hasColumn('ai_memory_deltas', 'promoted_memory_entry_id')) {
            $updates['promoted_memory_entry_id'] = $entry->id;
        }

        if (Schema::hasColumn('ai_memory_deltas', 'promoted_at')) {
            $updates['promoted_at'] = now();
        }

        $delta->forceFill($updates)->save();
    }

    private function workspaceScopeId(string $workspace): string
    {
        return hash('sha256', realpath($workspace) ?: $workspace);
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
