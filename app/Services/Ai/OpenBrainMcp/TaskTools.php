<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GOD-DEBULK FASE C: task-serving + task-lifecycle tool family extracted verbatim
 * from AtlasOpenBrainMcpService. atlas_next_task / atlas_task_report are thin
 * wrappers over AtlasTaskServingService; atlas_task_start / _progress / _complete
 * write append-only AtlasTaskEvent lifecycle rows (no provider, no runtime exec).
 * Bodies are byte-identical to the pre-split service; the façade delegates here.
 */
class TaskTools
{
    use OpenBrainMcpToolInput;

    /**
     * PART 2 · A7 — MCP `atlas_next_task`: PULL the next task for an opaque client. Thin wrapper over the
     * SAME {@see AtlasTaskServingService} the `atlas:task` CLI uses;
     * platform-free, client_id opaque, master-switch gated.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function nextTask(array $arguments): array
    {
        $client = trim((string) ($arguments['client_id'] ?? $arguments['client'] ?? ''));

        return app(AtlasTaskServingService::class)->next($client, [
            'tags' => array_values((array) ($arguments['tags'] ?? [])),
        ]);
    }

    /**
     * PART 2 · A7 — MCP `atlas_task_report`: hand back a served task's outcome and close/release the lease.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function taskReport(array $arguments): array
    {
        return app(AtlasTaskServingService::class)->report(
            trim((string) ($arguments['client_id'] ?? $arguments['client'] ?? '')),
            (string) ($arguments['task_packet_id'] ?? $arguments['task'] ?? ''),
            (string) ($arguments['lease_id'] ?? $arguments['lease'] ?? ''),
            [
                'outcome' => (string) ($arguments['outcome'] ?? 'success'),
                'evidence' => (array) ($arguments['evidence'] ?? []),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function taskStart(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_start');
        }

        $title = $this->string($arguments['title'] ?? null);
        if ($title === null) {
            return ['ok' => false, 'tool' => 'atlas_task_start', 'error' => 'title_required'];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $task = DB::transaction(function () use ($arguments, $title, $workspace): AtlasTask {
            $task = AtlasTask::create([
                'title' => $title,
                'description' => $this->string($arguments['objective'] ?? null),
                'status' => 'open',
                'domain' => $this->string($arguments['domain'] ?? null) ?: 'dev',
                'project_id' => $this->string($arguments['project_id'] ?? null),
                'metadata' => array_merge(
                    $this->object($arguments['metadata'] ?? []),
                    ['workspace' => $workspace, 'source' => 'mcp_tool'],
                ),
            ]);

            $this->recordTaskLifecycleEvent($task, 'started', [
                'title' => $task->title,
                'objective_present' => $task->description !== null && trim((string) $task->description) !== '',
                'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
                'project_id' => $task->project_id,
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);

            return $task;
        });

        return [
            'ok' => true,
            'tool' => 'atlas_task_start',
            'task_id' => (string) $task->id,
            'status' => $task->status,
            'event_type' => 'started',
            'created_at' => $task->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function taskProgress(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_progress');
        }

        $taskId = $this->string($arguments['task_id'] ?? null);
        $milestone = $this->string($arguments['milestone'] ?? null);

        if ($taskId === null || $milestone === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_id_and_milestone_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_not_found'];
        }

        $event = DB::transaction(function () use ($arguments, $task, $milestone): AtlasTaskEvent {
            return $this->recordTaskLifecycleEvent($task, 'milestone', [
                'milestone' => $milestone,
                'details' => $this->string($arguments['details'] ?? null),
                'progress_pct' => isset($arguments['progress_pct']) ? max(0, min(100, (int) $arguments['progress_pct'])) : null,
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);
        });

        return [
            'ok' => true,
            'tool' => 'atlas_task_progress',
            'task_id' => $taskId,
            'event_id' => (string) $event->id,
            'milestone' => $milestone,
            'recorded_at' => $event->occurred_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function taskComplete(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_complete');
        }

        $taskId = $this->string($arguments['task_id'] ?? null);
        if ($taskId === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_id_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_not_found'];
        }

        $event = DB::transaction(function () use ($arguments, $task): AtlasTaskEvent {
            $task->update([
                'status' => 'done',
                'completed_at' => now(),
            ]);

            return $this->recordTaskLifecycleEvent($task, 'completed', [
                'summary' => $this->string($arguments['summary'] ?? null),
                'files_changed' => is_array($arguments['files_changed'] ?? null) ? $this->stringList($arguments['files_changed']) : [],
                'outcome' => $this->string($arguments['outcome'] ?? null) ?: 'success',
                'memory_entry_ids' => is_array($arguments['memory_entry_ids'] ?? null) ? $this->stringList($arguments['memory_entry_ids']) : [],
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);
        });

        $task->refresh();

        return [
            'ok' => true,
            'tool' => 'atlas_task_complete',
            'task_id' => $taskId,
            'status' => 'done',
            'event_id' => (string) $event->id,
            'completed_at' => $task->completed_at?->toJSON(),
        ];
    }

    private function taskOrchestrationAvailable(): bool
    {
        return Schema::hasTable('atlas_tasks') && Schema::hasTable('atlas_task_events');
    }

    /**
     * @return array<string,mixed>
     */
    private function taskOrchestrationUnavailable(string $tool): array
    {
        return [
            'ok' => false,
            'tool' => $tool,
            'error' => 'task_orchestration_unavailable',
            'missing_tables' => array_values(array_filter([
                Schema::hasTable('atlas_tasks') ? null : 'atlas_tasks',
                Schema::hasTable('atlas_task_events') ? null : 'atlas_task_events',
            ])),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordTaskLifecycleEvent(AtlasTask $task, string $eventType, array $payload): AtlasTaskEvent
    {
        $previousEvent = $task->events()
            ->latest('occurred_at')
            ->latest('id')
            ->first();
        $eventSequence = (int) $task->events()->count() + 1;
        $eventPayload = [
            'schema_version' => 'atlas.task_orchestration.event.v1',
            'tool' => 'atlas_open_brain_mcp',
            'event_sequence' => $eventSequence,
            'previous_event_id' => $previousEvent?->id,
            'previous_event_hash' => data_get($previousEvent?->payload, 'event_hash'),
            ...$payload,
        ];
        $eventPayload['event_hash'] = $this->stableTaskEventHash($task, $eventType, $eventPayload);

        return AtlasTaskEvent::create([
            'task_id' => (string) $task->id,
            'event_type' => $eventType,
            'source' => 'mcp_tool',
            'payload' => $eventPayload,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableTaskEventHash(AtlasTask $task, string $eventType, array $payload): string
    {
        $hashPayload = $payload;
        unset($hashPayload['event_hash']);

        $encoded = json_encode($this->sortKeysRecursive([
            'task_id' => (string) $task->id,
            'event_type' => $eventType,
            'payload' => $hashPayload,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? '' : $encoded);
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
    }

    /**
     * @return array<string,mixed>
     */

    /**
     * @return array<int,string>
     */
}
