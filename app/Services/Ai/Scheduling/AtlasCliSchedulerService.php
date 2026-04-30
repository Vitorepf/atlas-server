<?php

namespace App\Services\Ai\Scheduling;

use App\Jobs\RunScheduledTaskJob;
use App\Models\AiScheduledTask;
use App\Support\AtlasSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AtlasCliSchedulerService
{
    public function __construct(
        private readonly ScheduleParser $parser,
    ) {}

    /**
     * @param  array<int,string>  $skillIds
     * @param  array<int,string>  $contextFromTaskIds
     * @param  array<string,mixed>  $metadata
     */
    public function addTask(
        string $title,
        string $prompt,
        string $schedule,
        array $skillIds = [],
        string $targetPlatform = 'local',
        ?string $targetDeviceId = null,
        ?string $workspace = null,
        ?int $repeatRemaining = null,
        array $contextFromTaskIds = [],
        bool $wrapResponse = true,
        array $metadata = [],
    ): AiScheduledTask {
        $title = trim($title);
        $prompt = trim($prompt);

        if ($title === '') {
            throw new InvalidArgumentException('Scheduled task title cannot be empty.');
        }

        if ($prompt === '') {
            throw new InvalidArgumentException('Scheduled task prompt cannot be empty.');
        }

        $parsed = $this->parser->parse($schedule);
        $kind = (string) $parsed['kind'];
        $repeatRemaining ??= $kind === 'once' ? 1 : null;
        if ($repeatRemaining !== null && $repeatRemaining < 1) {
            throw new InvalidArgumentException('repeat_remaining must be null or greater than zero.');
        }

        return AiScheduledTask::query()->create([
            'title' => Str::limit($title, 255, ''),
            'prompt' => $prompt,
            'schedule' => (string) $parsed['schedule'],
            'kind' => $kind,
            'skill_ids' => $this->normalizeSkillIds($skillIds),
            'target_platform' => $this->normalizeTargetPlatform($targetPlatform),
            'target_device_id' => $this->normalizeOptionalUuid($targetDeviceId),
            'workspace' => $this->normalizeWorkspace($workspace),
            'enabled' => true,
            'next_run_at' => $parsed['next_run_at'],
            'repeat_remaining' => $repeatRemaining,
            'context_from_task_ids' => $this->normalizeUuidList($contextFromTaskIds),
            'wrap_response' => $wrapResponse,
            'metadata' => array_merge($metadata, [
                'schedule_parsed' => $this->serializedParsedSchedule($parsed),
                'created_by' => $metadata['created_by'] ?? 'atlas_cli_schedule',
            ]),
        ]);
    }

    /**
     * @return array{dispatched:int,task_ids:array<int,string>,tasks:array<int,array<string,mixed>>}
     */
    public function tick(int $limit = 25, bool $dispatch = true): array
    {
        $limit = max(1, min($limit, 100));
        $claimed = DB::transaction(function () use ($limit): array {
            $tasks = AiScheduledTask::query()
                ->where('enabled', true)
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('repeat_remaining')->orWhere('repeat_remaining', '>', 0);
                })
                ->orderBy('next_run_at')
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = [];
            foreach ($tasks as $task) {
                try {
                    $this->advanceTaskAfterClaim($task);
                    $claimed[] = $task->id;
                } catch (Throwable $exception) {
                    $this->markClaimFailed($task, $exception);
                    report($exception);
                }
            }

            return $claimed;
        });

        if ($dispatch) {
            foreach ($claimed as $taskId) {
                RunScheduledTaskJob::dispatch($taskId);
            }
        }

        $tasks = AiScheduledTask::query()
            ->whereIn('id', $claimed)
            ->orderBy('title')
            ->get()
            ->map(fn (AiScheduledTask $task): array => $this->taskPayload($task))
            ->all();

        return [
            'dispatched' => count($claimed),
            'task_ids' => $claimed,
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array{would_dispatch:int,dispatched:int,task_ids:array<int,string>,tasks:array<int,array<string,mixed>>}
     */
    public function previewDueTasks(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $tasks = AiScheduledTask::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('repeat_remaining')->orWhere('repeat_remaining', '>', 0);
            })
            ->orderBy('next_run_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
        $taskIds = $tasks->pluck('id')->values()->all();

        return [
            'would_dispatch' => count($taskIds),
            'dispatched' => 0,
            'task_ids' => $taskIds,
            'tasks' => $tasks->map(fn (AiScheduledTask $task): array => $this->taskPayload($task))->all(),
        ];
    }

    public function runNow(string $id, bool $sync = true): AiScheduledTask
    {
        $task = $this->findTask($id);
        if (! $task->enabled) {
            throw new RuntimeException('Scheduled task is disabled. Resume it before run-now.');
        }

        if ($sync) {
            RunScheduledTaskJob::dispatchSync($task->id);
        } else {
            RunScheduledTaskJob::dispatch($task->id);
        }

        return $task->refresh();
    }

    public function pauseTask(string $id): AiScheduledTask
    {
        $task = $this->findTask($id);
        $task->update([
            'enabled' => false,
            'metadata' => array_merge($task->metadata ?? [], [
                'paused_at' => now()->toJSON(),
            ]),
        ]);

        return $task->refresh();
    }

    public function resumeTask(string $id): AiScheduledTask
    {
        $task = $this->findTask($id);
        $parsed = $this->parser->parse($task->schedule);
        $task->update([
            'enabled' => true,
            'next_run_at' => $parsed['next_run_at'],
            'metadata' => array_merge($task->metadata ?? [], [
                'resumed_at' => now()->toJSON(),
                'schedule_parsed' => $this->serializedParsedSchedule($parsed),
            ]),
        ]);

        return $task->refresh();
    }

    public function removeTask(string $id, bool $force = false): ?AiScheduledTask
    {
        $task = $this->findTask($id);
        if ($force) {
            $task->delete();

            return null;
        }

        $task->update([
            'enabled' => false,
            'next_run_at' => null,
            'metadata' => array_merge($task->metadata ?? [], [
                'removed_at' => now()->toJSON(),
                'removed_mode' => 'soft_delete',
            ]),
        ]);

        return $task->refresh();
    }

    public function findTask(string $id): AiScheduledTask
    {
        $task = AiScheduledTask::query()->find($id);
        if (! $task) {
            throw new RuntimeException("Scheduled task not found: {$id}");
        }

        return $task;
    }

    /**
     * @return array<string,mixed>
     */
    public function taskPayload(AiScheduledTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'schedule' => $task->schedule,
            'kind' => $task->kind,
            'enabled' => $task->enabled,
            'next_run_at' => $task->next_run_at?->toJSON(),
            'last_run_at' => $task->last_run_at?->toJSON(),
            'last_status' => $task->last_status,
            'last_output_path' => $task->last_output_path,
            'repeat_remaining' => $task->repeat_remaining,
            'target_platform' => $task->target_platform,
            'target_device_id' => $task->target_device_id,
            'workspace' => $task->workspace,
            'skill_ids' => $task->skill_ids ?? [],
            'context_from_task_ids' => $task->context_from_task_ids ?? [],
            'wrap_response' => $task->wrap_response,
            'metadata' => $task->metadata ?? [],
        ];
    }

    private function advanceTaskAfterClaim(AiScheduledTask $task): void
    {
        $remaining = $task->repeat_remaining;
        if ($remaining !== null) {
            $remaining = max(0, $remaining - 1);
        }

        $hasRemaining = $remaining === null || $remaining > 0;
        $parsed = $this->parser->parse($task->schedule);
        $nextRunAt = $hasRemaining ? $this->nextRunAfterClaim($task, $parsed) : null;
        $enabled = $task->kind !== 'once' && $hasRemaining;

        $task->update([
            'repeat_remaining' => $remaining,
            'next_run_at' => $nextRunAt,
            'enabled' => $enabled,
            'metadata' => array_merge($task->metadata ?? [], [
                'last_claimed_at' => now()->toJSON(),
                'last_claimed_next_run_at' => $nextRunAt instanceof CarbonImmutable ? $nextRunAt->toJSON() : null,
            ]),
        ]);
    }

    private function markClaimFailed(AiScheduledTask $task, Throwable $exception): void
    {
        $task->update([
            'enabled' => false,
            'next_run_at' => null,
            'last_status' => 'failure',
            'metadata' => array_merge($task->metadata ?? [], [
                'scheduler_disabled_at' => now()->toJSON(),
                'scheduler_disabled_reason' => 'invalid_schedule',
                'scheduler_error' => Str::limit($exception->getMessage(), 500, ''),
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $parsed
     */
    private function nextRunAfterClaim(AiScheduledTask $task, array $parsed): ?CarbonImmutable
    {
        if ($task->kind === 'once') {
            return null;
        }

        $from = $task->next_run_at instanceof CarbonImmutable ? $task->next_run_at : CarbonImmutable::instance(now());
        while ($from->lessThanOrEqualTo(now())) {
            $next = $this->parser->nextRunAt($parsed, $from);
            if (! $next || $next->equalTo($from)) {
                return null;
            }
            $from = $next;
        }

        return $from;
    }

    /**
     * @param  array<int,string>  $skills
     * @return array<int,string>
     */
    private function normalizeSkillIds(array $skills): array
    {
        return collect($skills)
            ->filter(fn (mixed $skill): bool => is_string($skill) && trim($skill) !== '')
            ->map(fn (mixed $skill): string => Str::of((string) $skill)->lower()->trim()->value())
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTargetPlatform(string $targetPlatform): string
    {
        $targetPlatform = Str::of($targetPlatform)->lower()->trim()->value();
        if (! in_array($targetPlatform, ['local', 'mobile', 'mobile_push'], true)) {
            throw new InvalidArgumentException('target_platform must be local, mobile or mobile_push.');
        }

        return $targetPlatform;
    }

    private function normalizeWorkspace(?string $workspace): ?string
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $resolved = AtlasSecurity::canonicalPath($workspace);
        if (! is_dir($resolved)) {
            throw new InvalidArgumentException("Workspace does not exist: {$workspace}");
        }

        return $resolved;
    }

    private function normalizeOptionalUuid(?string $id): ?string
    {
        $id = is_string($id) ? trim($id) : '';

        return $id !== '' ? $id : null;
    }

    /**
     * @param  array<int,string>  $ids
     * @return array<int,string>
     */
    private function normalizeUuidList(array $ids): array
    {
        return collect($ids)
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @return array<string,mixed>
     */
    private function serializedParsedSchedule(array $parsed): array
    {
        return collect($parsed)
            ->map(fn (mixed $value): mixed => $value instanceof CarbonImmutable ? $value->toJSON() : $value)
            ->all();
    }
}
