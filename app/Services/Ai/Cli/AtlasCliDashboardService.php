<?php

namespace App\Services\Ai\Cli;

use App\Models\AiJob;
use App\Models\AiProviderHealthSnapshot;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiScheduledTask;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;

class AtlasCliDashboardService
{
    public function __construct(
        private readonly WorkspaceProfiler $profiler,
        private readonly AiTelemetryScorecardService $scorecards,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(string $workspace, bool $refresh = false, int $limit = 8): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profiler->profile($workspace, $refresh);
        $limit = max(1, min($limit, 25));

        return [
            'generated_at' => now()->toJSON(),
            'workspace' => [
                'path' => $profile->workspace,
                'repo_root' => $profile->repoRoot,
                'branch' => $profile->branch,
                'head' => $profile->head,
                'stack' => $profile->stack,
                'package_manager' => $profile->packageManager,
                'dirty_count' => count($profile->dirtyFiles),
                'dirty_files' => array_slice($profile->dirtyFiles, 0, $limit),
                'important_files' => array_slice($profile->importantFiles, 0, $limit),
                'test_commands' => array_slice($profile->testCommands, 0, $limit),
                'file_sample_count' => (int) data_get($profile->metadata, 'file_count_sampled', count($profile->files)),
                'cache_key' => $profile->cacheKey,
            ],
            'atlas_ai' => $this->atlasAi($workspace),
            'providers' => $this->providers($limit),
            'runtime' => $this->runtime(),
            'recommended_commands' => $this->recommendedCommands($profile->dirtyFiles, $profile->testCommands),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasAi(string $workspace): array
    {
        $thread = $this->activeThread($workspace);

        return [
            'active_thread' => $thread,
            'traces' => $this->traceCounts(),
            'jobs' => $this->jobCounts(),
            'scheduled_tasks' => $this->scheduledTaskCounts(),
            'quality' => $this->quality(),
            'metrics' => $this->scorecards->build(now()->subDay()),
            'actions' => $this->actions(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeThread(string $workspace): ?array
    {
        if (! DatabaseTableAvailability::has('ai_threads')) {
            return null;
        }

        $query = AiThread::query()
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->where('status', 'active')
            ->with(['activeSession', 'activeState', 'lastTrace'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        $thread = $query->first();
        if (! $thread) {
            return null;
        }

        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'summary' => $thread->summary,
            'provider' => $thread->last_provider,
            'message_count' => $thread->message_count,
            'last_message_at' => $thread->last_message_at?->toJSON(),
            'session' => $thread->activeSession ? [
                'id' => $thread->activeSession->id,
                'status' => $thread->activeSession->status,
                'provider_primary' => $thread->activeSession->provider_primary,
                'provider_last' => $thread->activeSession->provider_last,
                'message_count' => $thread->activeSession->message_count,
                'started_at' => $thread->activeSession->started_at?->toJSON(),
            ] : null,
            'state' => $thread->activeState ? [
                'id' => $thread->activeState->id,
                'version' => $thread->activeState->version,
                'objective' => $thread->activeState->objective,
                'current_phase' => $thread->activeState->current_phase,
                'current_topic' => $thread->activeState->current_topic,
                'decisions_count' => count($thread->activeState->decisions ?? []),
                'open_loops_count' => count($thread->activeState->open_loops ?? []),
                'next_steps_count' => count($thread->activeState->next_steps ?? []),
                'updated_at' => $thread->activeState->updated_at?->toJSON(),
            ] : null,
            'last_trace' => $thread->lastTrace ? [
                'id' => $thread->lastTrace->id,
                'status' => $thread->lastTrace->status,
                'provider' => $thread->lastTrace->provider,
                'agent' => $thread->lastTrace->agent_slug,
                'created_at' => $thread->lastTrace->created_at?->toJSON(),
            ] : null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function traceCounts(): array
    {
        if (! DatabaseTableAvailability::has('ai_traces')) {
            return [];
        }

        return [
            'queued' => AiTrace::query()->where('status', 'queued')->count(),
            'processing' => AiTrace::query()->where('status', 'processing')->count(),
            'failed_24h' => AiTrace::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'total_24h' => AiTrace::query()->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function jobCounts(): array
    {
        if (! DatabaseTableAvailability::has('ai_jobs')) {
            return [];
        }

        return [
            'queued' => AiJob::query()->where('status', 'queued')->count(),
            'processing' => AiJob::query()->where('status', 'processing')->count(),
            'failed_24h' => AiJob::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * @return array<string,int|string|null>
     */
    private function scheduledTaskCounts(): array
    {
        if (! DatabaseTableAvailability::has('ai_scheduled_tasks')) {
            return [];
        }

        $nextRun = AiScheduledTask::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at')
            ->value('next_run_at');

        return [
            'enabled' => AiScheduledTask::query()->where('enabled', true)->count(),
            'due' => AiScheduledTask::query()->where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->count(),
            'failed_24h' => AiScheduledTask::query()->where('last_status', 'failure')->where('last_run_at', '>=', now()->subDay())->count(),
            'next_run_at' => $nextRun ? (string) $nextRun : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function quality(): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
            return ['available' => false];
        }

        $since = now()->subDay();
        $average = AiQualityEvaluation::query()
            ->where('created_at', '>=', $since)
            ->avg('score');

        return [
            'available' => true,
            'average_score_24h' => $average === null ? null : round((float) $average, 2),
            'needs_review' => AiQualityEvaluation::query()->where('status', 'needs_review')->where('created_at', '>=', $since)->count(),
            'failed' => AiQualityEvaluation::query()->where('status', 'failed')->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function actions(): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_actions')) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'open' => AiQualityAction::query()->whereIn('status', ['queued', 'running', 'blocked', 'failed'])->count(),
            'queued' => AiQualityAction::query()->where('status', 'queued')->count(),
            'blocked' => AiQualityAction::query()->where('status', 'blocked')->count(),
            'failed' => AiQualityAction::query()->where('status', 'failed')->count(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function providers(int $limit): array
    {
        if (! DatabaseTableAvailability::has('ai_provider_health_snapshots')) {
            return [];
        }

        return AiProviderHealthSnapshot::query()
            ->orderByDesc('checked_at')
            ->limit(max(10, $limit * 3))
            ->get()
            ->unique('provider')
            ->take($limit)
            ->map(fn (AiProviderHealthSnapshot $snapshot): array => [
                'provider' => $snapshot->provider,
                'status' => $snapshot->status,
                'pain' => $snapshot->operational_pain_score,
                'p50_latency_ms' => $snapshot->p50_latency_ms,
                'checked_at' => $snapshot->checked_at?->toJSON(),
                'message' => $snapshot->message,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function runtime(): array
    {
        $permissions = config('atlas.ai.tool_permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];

        return [
            'default_permission' => (string) ($permissions['default_mode'] ?? 'read'),
            'danger_allowed' => (bool) ($permissions['allow_danger'] ?? false),
            'unsandboxed_write_allowed' => (bool) ($permissions['allow_unsandboxed_write'] ?? false),
            'allowed_roots' => array_values(array_filter((array) ($permissions['allowed_roots'] ?? []), 'is_string')),
            'native_tools' => [
                'workspace.profile',
                'package.detect',
                'file.read',
                'file.write',
                'file.patch',
                'session.search',
                'search.rg',
                'shell.run',
                'git.status',
                'git.diff',
                'git.apply_patch',
                'test.run',
                'checkpoint.restore',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $dirtyFiles
     * @param  array<int,string>  $testCommands
     * @return array<int,string>
     */
    private function recommendedCommands(array $dirtyFiles, array $testCommands): array
    {
        $commands = [
            'atlas dev --allow-write',
            'atlas plan',
            'atlas runtime package.detect',
        ];

        if ($dirtyFiles !== []) {
            array_unshift($commands, 'atlas review --stream', 'atlas runtime git.diff');
        }

        if ($testCommands !== []) {
            $commands[] = 'atlas runtime test.run --yes';
        }

        $commands[] = 'atlas bootstrap --strict';

        return array_values(array_unique($commands));
    }
}
