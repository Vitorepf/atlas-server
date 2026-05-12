<?php

namespace App\Services\Ai;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasProject;
use App\Models\AtlasTask;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasMemoryRegistryService
{
    private AtlasMemoryPrivacyService $privacy;

    private MemoryQueryInput $input;

    public function __construct(?AtlasMemoryPrivacyService $privacy = null, ?MemoryQueryInput $input = null)
    {
        $this->privacy = $privacy ?? app(AtlasMemoryPrivacyService::class);
        $this->input = $input ?? app(MemoryQueryInput::class);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): AtlasMemoryEntry
    {
        $payload = $this->normalize($attributes);

        return AtlasMemoryEntry::query()->create($payload);
    }

    /**
     * @param  array<string,mixed>  $identity
     * @param  array<string,mixed>  $attributes
     */
    public function upsert(array $identity, array $attributes): AtlasMemoryEntry
    {
        $payload = $this->normalize($attributes);

        return AtlasMemoryEntry::query()->updateOrCreate($identity, $payload);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function search(array $filters = [], int $limit = 50): Collection
    {
        $query = AtlasMemoryEntry::query();

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

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function forScope(string $scopeType, ?string $scopeId = null, array $filters = [], int $limit = 50): Collection
    {
        return $this->applyFilters(AtlasMemoryEntry::query()->forScope($scopeType, $scopeId), $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForTask(AtlasTask $task, array $filters = [], int $limit = 25): Collection
    {
        $task->loadMissing('project');

        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($task): void {
            $query->where('scope_type', 'global')
                ->orWhere('task_id', $task->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'task')
                    ->where('scope_id', $task->id));

            if ($task->project_id) {
                $query->orWhere('project_id', $task->project_id)
                    ->orWhere(fn (Builder $nested) => $nested
                        ->where('scope_type', 'project')
                        ->where('scope_id', $task->project_id));
            }
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForProject(AtlasProject $project, array $filters = [], int $limit = 25): Collection
    {
        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($project): void {
            $query->where('scope_type', 'global')
                ->orWhere('project_id', $project->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'project')
                    ->where('scope_id', $project->id));
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForRun(AtlasEngineeringRun $run, array $filters = [], int $limit = 25): Collection
    {
        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($run): void {
            $query->where('scope_type', 'global')
                ->orWhere('engineering_run_id', $run->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'engineering_run')
                    ->where('scope_id', $run->id));

            if ($run->task_id) {
                $query->orWhere('task_id', $run->task_id);
            }
            if ($run->project_id) {
                $query->orWhere('project_id', $run->project_id);
            }
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForContext(array $context, array $filters = [], int $limit = 12): Collection
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
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

        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($projectId, $taskId, $runId, $sessionId, $userId, $workspaceId): void {
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
     * @param  array<string,mixed>  $context
     */
    public function recordHarnessLearning(AtlasEngineeringRun $run, array $context = []): ?AtlasMemoryEntry
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return null;
        }

        $run->loadMissing('task');
        $decision = (string) ($run->decision ?: 'unknown');
        $score = $run->score === null ? 'n/a' : (string) $run->score;
        $blockingReasons = array_values((array) data_get($run->metadata, 'blocking_reasons', []));
        $body = 'Engineering Harness Runner finalizou com decision='.$decision.' score='.$score.'.';
        if ($blockingReasons !== []) {
            $body .= ' Bloqueios/observacoes: '.implode(' | ', array_map('strval', $blockingReasons));
        }

        return AtlasMemoryEntry::query()->updateOrCreate([
            'memory_type' => 'harness_learning',
            'source_type' => 'engineering_run',
            'source_id' => $run->id,
        ], $this->normalize([
            'memory_type' => 'harness_learning',
            'scope_type' => 'engineering_run',
            'scope_id' => $run->id,
            'project_id' => $run->project_id,
            'task_id' => $run->task_id,
            'engineering_run_id' => $run->id,
            'trace_id' => $run->trace_id,
            'title' => 'Harness run '.$decision,
            'body' => $body,
            'summary' => Str::limit($body, 240),
            'importance' => in_array($decision, ['unsafe', 'unresolved'], true) ? 4 : 3,
            'priority' => match ($decision) {
                'unsafe', 'unresolved' => 90,
                'partial' => 75,
                default => 60,
            },
            'confidence' => $decision === 'resolved' ? 0.92 : 0.7,
            'source_type' => 'engineering_run',
            'source_id' => $run->id,
            'source_label' => 'Atlas Engineering Harness Runner',
            'metadata' => array_merge($context, [
                'decision' => $decision,
                'status' => $run->status,
                'score' => $run->score,
                'attempt_count' => $run->attempt_count,
                'context_pack_id' => $run->context_pack_id,
                'context_pack_hash' => $run->context_pack_hash,
                'blocking_reasons' => $blockingReasons,
            ]),
        ]));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function sourceMap(): array
    {
        return [
            ['source' => 'tasks', 'tables' => ['atlas_tasks', 'atlas_task_events'], 'memory_role' => 'task intent, execution state, decisions and event history'],
            ['source' => 'projects', 'tables' => ['atlas_projects', 'atlas_project_events', 'atlas_project_steps', 'atlas_project_blockers'], 'memory_role' => 'project scope, outcomes, blockers and delivery flow'],
            ['source' => 'notes', 'tables' => ['semantic_notes', 'semantic_note_links', 'semantic_note_activations', 'semantic_curation_proposals'], 'memory_role' => 'human-readable semantic notes, activations and curation proposals', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'ai_interactions', 'tables' => ['ai_traces', 'ai_jobs', 'ai_messages', 'ai_threads', 'ai_sessions', 'ai_context_snapshots', 'ai_memory_deltas'], 'memory_role' => 'provider traces, feedback, session continuity and reviewed deltas', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'engineering_harness', 'tables' => ['atlas_engineering_runs', 'atlas_engineering_run_attempts', 'atlas_engineering_context_packs', 'atlas_engineering_evidence', 'atlas_engineering_review_findings', 'atlas_engineering_test_runs', 'atlas_engineering_benchmark_results', 'atlas_engineering_patch_artifacts'], 'memory_role' => 'engineering run outcomes, context packs, evidence, tests, findings and benchmark observations', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'engineering_knowledge', 'tables' => ['atlas_engineering_knowledge_items'], 'memory_role' => 'canonical architecture, maintenance playbooks, ADRs and capability registry for engineering work', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'memory_quality', 'tables' => ['atlas_memory_quality_snapshots'], 'memory_role' => 'operational scorecard history for memory readiness, provider-safety, governance, freshness, feedback and completeness', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'attachments', 'tables' => ['ai_attachment_index_entries'], 'memory_role' => 'uploaded images/documents, rendered pages, OCR excerpts and captions', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'context_bundles', 'tables' => ['ai_context_bundles'], 'memory_role' => 'mobile/app context bundles prepared for user-facing follow-up', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function normalize(array $attributes): array
    {
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

        if ($runId && (! $taskId || ! $projectId)) {
            $run = AtlasEngineeringRun::query()->find($runId);
            $taskId ??= $run?->task_id;
            $projectId ??= $run?->project_id;
        }

        if ($taskId && ! $projectId) {
            $projectId = AtlasTask::query()->find($taskId)?->project_id;
        }

        $status = in_array($attributes['status'] ?? 'active', AtlasMemoryEntry::STATUSES, true)
            ? (string) ($attributes['status'] ?? 'active')
            : 'active';
        $memoryType = $this->memoryType($attributes['memory_type'] ?? ($attributes['type'] ?? 'technical_context'));
        $body = trim((string) ($attributes['body'] ?? $attributes['content'] ?? ''));
        $summary = isset($attributes['summary']) ? trim((string) $attributes['summary']) : null;

        $payload = [
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'global' ? null : $scopeId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'engineering_run_id' => $runId,
            'trace_id' => $this->uuidOrNull($attributes['trace_id'] ?? null),
            'session_id' => $this->uuidOrNull($attributes['session_id'] ?? null),
            'user_id' => isset($attributes['user_id']) ? trim((string) $attributes['user_id']) : null,
            'title' => isset($attributes['title']) ? Str::limit(trim((string) $attributes['title']), 180, '') : null,
            'body' => $body,
            'summary' => $summary,
            'importance' => max(1, min(5, (int) ($attributes['importance'] ?? 3))),
            'priority' => max(0, min(100, (int) ($attributes['priority'] ?? 50))),
            'confidence' => $this->confidence($attributes['confidence'] ?? null),
            'source_type' => trim((string) ($attributes['source_type'] ?? 'manual')),
            'source_id' => isset($attributes['source_id']) ? trim((string) $attributes['source_id']) : null,
            'source_label' => isset($attributes['source_label']) ? Str::limit(trim((string) $attributes['source_label']), 180, '') : null,
            'status' => $status,
            'tags' => array_values(array_filter((array) ($attributes['tags'] ?? []), fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')),
            'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            'recorded_at' => $attributes['recorded_at'] ?? now(),
            'last_used_at' => $attributes['last_used_at'] ?? null,
            'archived_at' => $status === 'archived' ? ($attributes['archived_at'] ?? now()) : ($attributes['archived_at'] ?? null),
        ];

        if (Schema::hasColumn('atlas_memory_entries', 'content_hash')) {
            $payload['content_hash'] = $this->contentHash($attributes['content_hash'] ?? null, $memoryType, $scopeType, $scopeId, $body, $summary);
        }

        return $this->privacy->normalizeForStorage($payload, $attributes);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);
        $requestedStatus = is_string($filters['status'] ?? null) && $filters['status'] !== '' ? $filters['status'] : null;
        if (! $includeInactive && $requestedStatus === null) {
            $query->active();
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? $filters['memory_type'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('memory_type', $types);
        }

        if (is_string($filters['source_type'] ?? null) && $filters['source_type'] !== '') {
            $query->where('source_type', $filters['source_type']);
        }

        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '' && Schema::hasColumn('atlas_memory_entries', 'privacy_class')) {
            $query->where('privacy_class', $filters['privacy_class']);
        }

        if ($requestedStatus !== null) {
            $query->where('status', $requestedStatus);
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('importance')
            ->latest('recorded_at');
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

    private function memoryType(mixed $type): string
    {
        $type = is_string($type) ? $type : 'technical_context';

        return in_array($type, AtlasMemoryEntry::TYPES, true) ? $type : 'technical_context';
    }

    private function confidence(mixed $confidence): ?float
    {
        if ($confidence === null || $confidence === '') {
            return null;
        }

        return max(0, min(1, (float) $confidence));
    }

    private function contentHash(mixed $hash, string $memoryType, string $scopeType, ?string $scopeId, string $body, ?string $summary): string
    {
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) {
            return strtolower($hash);
        }

        return hash('sha256', json_encode([
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'body' => $body,
            'summary' => $summary,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
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
        return $this->input->registryLimit($limit);
    }
}
