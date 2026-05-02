<?php

namespace App\Services\Ai;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasTask;
use App\Models\AtlasVerbatimMemory;
use App\Support\AtlasSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasVerbatimMemoryService
{
    public function __construct(private readonly AtlasMemoryRegistryService $registry) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): AtlasVerbatimMemory
    {
        return DB::transaction(function () use ($attributes): AtlasVerbatimMemory {
            $memory = AtlasVerbatimMemory::query()->create($this->normalize($attributes));

            if (($attributes['link_registry'] ?? true) !== false && Schema::hasTable('atlas_memory_entries')) {
                $this->syncRegistryPointer($memory);
            }

            return $memory->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasVerbatimMemory>
     */
    public function search(array $filters = [], int $limit = 50): Collection
    {
        $query = AtlasVerbatimMemory::query();

        if (is_string($filters['scope_type'] ?? null) && $filters['scope_type'] !== '') {
            $query->where('scope_type', $filters['scope_type']);
        }
        if (is_string($filters['scope_id'] ?? null) && $filters['scope_id'] !== '') {
            $query->where('scope_id', $filters['scope_id']);
        }
        foreach (['project_id', 'task_id', 'engineering_run_id', 'trace_id', 'session_id', 'user_id'] as $column) {
            if (is_string($filters[$column] ?? null) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        $entries = $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();

        $tags = array_values(array_filter(
            (array) ($filters['tags'] ?? $filters['tag'] ?? []),
            fn (mixed $tag): bool => is_string($tag) && $tag !== '',
        ));
        if ($tags === []) {
            return $entries;
        }

        return $entries
            ->filter(fn (AtlasVerbatimMemory $memory): bool => $this->hasAllTags($memory, $tags))
            ->values();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasVerbatimMemory>
     */
    public function relevantForContext(array $context, array $filters = [], int $limit = 12): Collection
    {
        if (! Schema::hasTable('atlas_verbatim_memories')) {
            return collect();
        }

        $projectId = $this->uuidOrNull($context['project_id'] ?? null);
        $taskId = $this->uuidOrNull($context['task_id'] ?? null);
        $runId = $this->uuidOrNull($context['engineering_run_id'] ?? ($context['run_id'] ?? null));
        $sessionId = $this->uuidOrNull($context['session_id'] ?? null);
        $userId = $this->stringOrNull($context['user_id'] ?? null);
        $workspaceId = $this->workspaceScopeId($context['workspace'] ?? ($context['workspace_path'] ?? null));

        if ($runId && (! $projectId || ! $taskId) && Schema::hasTable('atlas_engineering_runs')) {
            $run = AtlasEngineeringRun::query()->find($runId);
            $projectId ??= $run?->project_id;
            $taskId ??= $run?->task_id;
        }

        if ($taskId && ! $projectId && Schema::hasTable('atlas_tasks')) {
            $projectId = AtlasTask::query()->find($taskId)?->project_id;
        }

        $query = AtlasVerbatimMemory::query()->where(function (Builder $query) use ($projectId, $taskId, $runId, $sessionId, $userId, $workspaceId): void {
            $query->where('scope_type', 'global');

            if ($projectId) {
                $query->orWhere('project_id', $projectId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'project')->where('scope_id', $projectId));
            }

            if ($taskId) {
                $query->orWhere('task_id', $taskId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'task')->where('scope_id', $taskId));
            }

            if ($runId) {
                $query->orWhere('engineering_run_id', $runId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'engineering_run')->where('scope_id', $runId));
            }

            if ($sessionId) {
                $query->orWhere('session_id', $sessionId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'session')->where('scope_id', $sessionId));
            }

            if ($userId) {
                $query->orWhere('user_id', $userId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'user')->where('scope_id', $userId));
            }

            if ($workspaceId) {
                $query->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'workspace')->where('scope_id', $workspaceId));
            }
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function transition(AtlasVerbatimMemory $memory, string $status, array $metadata = []): AtlasVerbatimMemory
    {
        $status = in_array($status, AtlasVerbatimMemory::STATUSES, true) ? $status : 'active';
        $existing = is_array($memory->metadata) ? $memory->metadata : [];

        $memory->forceFill([
            'status' => $status,
            'metadata' => array_merge($existing, AtlasSecurity::redactArray($metadata)),
            'archived_at' => $status === 'archived' ? ($memory->archived_at ?: now()) : null,
        ])->save();

        if (Schema::hasTable('atlas_memory_entries')) {
            $this->syncRegistryPointer($memory->refresh());
        }

        return $memory->refresh();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function review(AtlasVerbatimMemory $memory, array $data): AtlasVerbatimMemory
    {
        return DB::transaction(function () use ($memory, $data): AtlasVerbatimMemory {
            $privacyClass = $this->privacyClass($data['privacy_class'] ?? $data['privacy'] ?? $memory->privacy_class);
            $externalAiAllowed = $this->externalAiAllowed(
                $privacyClass,
                array_key_exists('external_ai_allowed', $data) ? $data['external_ai_allowed'] : $memory->external_ai_allowed,
            );
            $redacted = $this->reviewRedactedText($memory, $data);
            $status = in_array($data['status'] ?? $memory->status, AtlasVerbatimMemory::STATUSES, true)
                ? (string) ($data['status'] ?? $memory->status)
                : $memory->status;
            $redactionStatus = $redacted === $memory->verbatim_text ? 'clean' : 'redacted';
            $metadata = $this->reviewMetadata($memory, $data, $privacyClass, $externalAiAllowed, $redactionStatus);

            $memory->forceFill([
                'redacted_text' => $redacted,
                'summary' => array_key_exists('summary', $data) ? AtlasSecurity::redactString((string) $data['summary']) : $memory->summary,
                'privacy_class' => $privacyClass,
                'external_ai_allowed' => $externalAiAllowed,
                'redaction_status' => $redactionStatus,
                'redacted_hash' => hash('sha256', $redacted),
                'status' => $status,
                'metadata' => $metadata,
                'archived_at' => $status === 'archived' ? ($memory->archived_at ?: now()) : null,
            ])->save();

            if (Schema::hasTable('atlas_memory_entries')) {
                $this->syncRegistryPointer($memory->refresh());
            }

            return $memory->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function normalize(array $attributes): array
    {
        $verbatim = trim((string) ($attributes['verbatim_text'] ?? $attributes['verbatim'] ?? $attributes['body'] ?? ''));
        $redacted = AtlasSecurity::redactString($verbatim);
        $privacyClass = $this->privacyClass($attributes['privacy_class'] ?? $attributes['privacy'] ?? 'normal');
        $externalAiAllowed = $this->externalAiAllowed($privacyClass, $attributes['external_ai_allowed'] ?? null);
        $scopeType = $this->scopeType($attributes);
        $scopeId = $this->scopeId($scopeType, $attributes);
        $projectId = $this->uuidOrNull($attributes['project_id'] ?? null);
        $taskId = $this->uuidOrNull($attributes['task_id'] ?? null);
        $runId = $this->uuidOrNull($attributes['engineering_run_id'] ?? ($attributes['run_id'] ?? null));

        if ($scopeType === 'project') {
            $projectId ??= $this->uuidOrNull($scopeId);
        }
        if ($scopeType === 'task') {
            $taskId ??= $this->uuidOrNull($scopeId);
        }
        if ($scopeType === 'engineering_run') {
            $runId ??= $this->uuidOrNull($scopeId);
        }

        if ($runId && (! $taskId || ! $projectId) && Schema::hasTable('atlas_engineering_runs')) {
            $run = AtlasEngineeringRun::query()->find($runId);
            $taskId ??= $run?->task_id;
            $projectId ??= $run?->project_id;
        }

        if ($taskId && ! $projectId && Schema::hasTable('atlas_tasks')) {
            $projectId = AtlasTask::query()->find($taskId)?->project_id;
        }

        $status = in_array($attributes['status'] ?? 'active', AtlasVerbatimMemory::STATUSES, true)
            ? (string) ($attributes['status'] ?? 'active')
            : 'active';
        $redactionStatus = $redacted === $verbatim ? 'clean' : 'redacted';
        $metadata = AtlasSecurity::redactArray(is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : []);

        return [
            'verbatim_type' => $this->verbatimType($attributes['verbatim_type'] ?? ($attributes['type'] ?? 'decision')),
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'global' ? null : $scopeId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'engineering_run_id' => $runId,
            'trace_id' => $this->uuidOrNull($attributes['trace_id'] ?? null),
            'session_id' => $this->uuidOrNull($attributes['session_id'] ?? null),
            'user_id' => isset($attributes['user_id']) ? trim((string) $attributes['user_id']) : null,
            'title' => isset($attributes['title']) ? Str::limit(AtlasSecurity::redactString(trim((string) $attributes['title'])), 180, '') : null,
            'verbatim_text' => $verbatim,
            'redacted_text' => $redacted,
            'summary' => isset($attributes['summary']) ? trim(AtlasSecurity::redactString((string) $attributes['summary'])) : null,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
            'content_hash' => hash('sha256', $verbatim),
            'redacted_hash' => hash('sha256', $redacted),
            'source_type' => trim((string) ($attributes['source_type'] ?? 'manual')),
            'source_id' => isset($attributes['source_id']) ? trim((string) $attributes['source_id']) : null,
            'source_label' => isset($attributes['source_label']) ? Str::limit(AtlasSecurity::redactString(trim((string) $attributes['source_label'])), 180, '') : null,
            'status' => $status,
            'tags' => array_values(array_filter((array) ($attributes['tags'] ?? []), fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')),
            'metadata' => array_merge($metadata, [
                'privacy' => [
                    'class' => $privacyClass,
                    'external_ai_allowed' => $externalAiAllowed,
                    'redaction_status' => $redactionStatus,
                    'redacted_at' => $redactionStatus === 'redacted' ? now()->toJSON() : null,
                ],
            ]),
            'recorded_at' => $attributes['recorded_at'] ?? now(),
            'last_used_at' => $attributes['last_used_at'] ?? null,
            'archived_at' => $status === 'archived' ? ($attributes['archived_at'] ?? now()) : ($attributes['archived_at'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function registryPayload(AtlasVerbatimMemory $memory): array
    {
        $blocked = $memory->external_ai_allowed === false;
        $body = $blocked
            ? 'Verbatim memory registered; exact content is blocked by Atlas privacy policy. Internal verbatim id: '.$memory->id.'.'
            : $memory->redacted_text;

        return [
            'memory_type' => $this->registryMemoryType($memory->verbatim_type),
            'scope_type' => $memory->scope_type,
            'scope_id' => $memory->scope_id,
            'project_id' => $memory->project_id,
            'task_id' => $memory->task_id,
            'engineering_run_id' => $memory->engineering_run_id,
            'trace_id' => $memory->trace_id,
            'session_id' => $memory->session_id,
            'user_id' => $memory->user_id,
            'title' => $blocked ? 'Verbatim '.$memory->verbatim_type.' bloqueado por privacidade' : $memory->title,
            'body' => $body,
            'summary' => $blocked ? 'Conteudo verbatim disponivel apenas via acesso interno explicito.' : ($memory->summary ?: Str::limit($memory->redacted_text, 240)),
            'importance' => in_array($memory->verbatim_type, ['decision', 'requirement', 'failure'], true) ? 4 : 3,
            'priority' => in_array($memory->verbatim_type, ['decision', 'requirement'], true) ? 80 : 65,
            'confidence' => 0.95,
            'source_type' => 'atlas_verbatim_memory',
            'source_id' => $memory->id,
            'source_label' => 'Atlas Verbatim Store',
            'status' => $memory->status,
            'tags' => array_values(array_unique(array_merge((array) $memory->tags, ['verbatim', $memory->verbatim_type]))),
            'metadata' => [
                'verbatim_memory_id' => $memory->id,
                'verbatim_type' => $memory->verbatim_type,
                'privacy' => [
                    'class' => $memory->privacy_class,
                    'external_ai_allowed' => $memory->external_ai_allowed,
                    'redaction_status' => $memory->redaction_status,
                ],
            ],
            'recorded_at' => $memory->recorded_at,
            'archived_at' => $memory->archived_at,
        ];
    }

    private function syncRegistryPointer(AtlasVerbatimMemory $memory): ?AtlasMemoryEntry
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return null;
        }

        $payload = $this->registryPayload($memory);
        $entry = $memory->memory_entry_id
            ? AtlasMemoryEntry::query()->find($memory->memory_entry_id)
            : null;

        if ($entry) {
            $entry->forceFill($payload)->save();
        } else {
            $entry = $this->registry->record($payload);
        }

        if ($memory->memory_entry_id !== $entry->id) {
            $memory->forceFill(['memory_entry_id' => $entry->id])->save();
        }

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function reviewRedactedText(AtlasVerbatimMemory $memory, array $data): string
    {
        if (array_key_exists('redacted_text', $data) && is_string($data['redacted_text'])) {
            return AtlasSecurity::redactString(trim($data['redacted_text']));
        }

        if (($data['re_redact'] ?? false) === true) {
            return AtlasSecurity::redactString((string) $memory->verbatim_text);
        }

        return (string) $memory->redacted_text;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function reviewMetadata(
        AtlasVerbatimMemory $memory,
        array $data,
        string $privacyClass,
        bool $externalAiAllowed,
        string $redactionStatus,
    ): array {
        $metadata = is_array($memory->metadata) ? $memory->metadata : [];
        $history = array_values((array) ($metadata['review_history'] ?? []));
        $history[] = AtlasSecurity::redactArray([
            'reviewed_at' => now()->toJSON(),
            'reviewed_by' => is_scalar($data['reviewed_by'] ?? null) ? (string) $data['reviewed_by'] : null,
            'action' => is_scalar($data['review_action'] ?? null) ? (string) $data['review_action'] : 'review',
            'note' => is_scalar($data['review_note'] ?? null) ? (string) $data['review_note'] : null,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
        ]);

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $metadata['privacy'] = [
            'class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => $redactionStatus,
            'reviewed_at' => now()->toJSON(),
        ];
        $metadata['review_history'] = $history;

        if (is_array($data['metadata'] ?? null)) {
            $metadata = array_merge($metadata, AtlasSecurity::redactArray($data['metadata']));
        }

        return $metadata;
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);
        $requestedStatus = is_string($filters['status'] ?? null) && $filters['status'] !== '' ? $filters['status'] : null;
        if (! $includeInactive && $requestedStatus === null) {
            $query->active();
        }

        $types = array_values(array_filter(
            (array) ($filters['types'] ?? $filters['verbatim_type'] ?? []),
            fn (mixed $type): bool => is_string($type) && $type !== '',
        ));
        if ($types !== []) {
            $query->whereIn('verbatim_type', $types);
        }
        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '') {
            $query->where('privacy_class', $filters['privacy_class']);
        }
        if (is_string($filters['source_type'] ?? null) && $filters['source_type'] !== '') {
            $query->where('source_type', $filters['source_type']);
        }
        if ($requestedStatus !== null) {
            $query->where('status', $requestedStatus);
        }

        return $query
            ->orderByDesc('recorded_at')
            ->latest('created_at');
    }

    /**
     * @param  array<int,string>  $tags
     */
    private function hasAllTags(AtlasVerbatimMemory $memory, array $tags): bool
    {
        $existing = array_map('strval', (array) $memory->tags);

        return array_diff($tags, $existing) === [];
    }

    private function verbatimType(mixed $type): string
    {
        $type = is_string($type) ? $type : 'decision';

        return in_array($type, AtlasVerbatimMemory::TYPES, true) ? $type : 'decision';
    }

    private function privacyClass(mixed $privacyClass): string
    {
        $privacyClass = is_string($privacyClass) ? $privacyClass : 'normal';

        return in_array($privacyClass, AtlasVerbatimMemory::PRIVACY_CLASSES, true) ? $privacyClass : 'normal';
    }

    private function externalAiAllowed(string $privacyClass, mixed $requested): bool
    {
        $blocked = array_values(array_unique(array_merge(
            (array) config('atlas.privacy.block_external_ai_for_sensitivity', []),
            ['secret'],
        )));

        if (in_array($privacyClass, $blocked, true)) {
            return false;
        }

        return $requested === null ? true : filter_var($requested, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function scopeType(array $attributes): string
    {
        $scopeType = (string) ($attributes['scope_type'] ?? '');
        if ($scopeType === '' && isset($attributes['scope'])) {
            $scopeType = (string) $attributes['scope'];
        }
        if ($scopeType === '') {
            $scopeType = match (true) {
                isset($attributes['engineering_run_id']) || isset($attributes['run_id']) => 'engineering_run',
                isset($attributes['task_id']) => 'task',
                isset($attributes['project_id']) => 'project',
                isset($attributes['workspace']) || isset($attributes['workspace_id']) => 'workspace',
                isset($attributes['session_id']) => 'session',
                isset($attributes['user_id']) => 'user',
                default => 'global',
            };
        }

        return in_array($scopeType, AtlasMemoryEntry::SCOPES, true) ? $scopeType : 'global';
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function scopeId(string $scopeType, array $attributes): ?string
    {
        if ($scopeType === 'global') {
            return null;
        }

        $explicit = $attributes['scope_id'] ?? null;
        if (is_scalar($explicit) && trim((string) $explicit) !== '') {
            return trim((string) $explicit);
        }

        return match ($scopeType) {
            'project' => $this->stringOrNull($attributes['project_id'] ?? null),
            'task' => $this->stringOrNull($attributes['task_id'] ?? null),
            'engineering_run' => $this->stringOrNull($attributes['engineering_run_id'] ?? ($attributes['run_id'] ?? null)),
            'workspace' => $this->stringOrNull($attributes['workspace_id'] ?? null) ?? $this->workspaceScopeId($attributes['workspace'] ?? null),
            'session' => $this->stringOrNull($attributes['session_id'] ?? null),
            'user' => $this->stringOrNull($attributes['user_id'] ?? null),
            default => null,
        };
    }

    private function registryMemoryType(string $verbatimType): string
    {
        return match ($verbatimType) {
            'decision' => 'decision',
            'failure' => 'issue',
            default => 'technical_context',
        };
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function workspaceScopeId(mixed $workspace): ?string
    {
        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            return null;
        }

        $workspace = trim((string) $workspace);

        return hash('sha256', realpath($workspace) ?: $workspace);
    }

    private function limit(int $limit): int
    {
        return max(1, min(200, $limit));
    }
}
