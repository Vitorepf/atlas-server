<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AtlasAiTaskOrchestrationBackfillReceiptsCommand extends Command
{
    protected $signature = 'atlas:ai:task-orchestration-backfill-receipts
        {--hours=720 : Window size in hours}
        {--write : Persist repaired receipt/hash-chain payloads}
        {--json : Print machine-readable JSON}';

    protected $description = 'Backfill local Atlas task orchestration receipts and hash chains without executing providers, runtimes or agents.';

    public function handle(KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $write = (bool) $this->option('write');
        $since = now()->subHours($hours);
        $until = now();

        if (! Schema::hasTable('atlas_tasks') || ! Schema::hasTable('atlas_task_events')) {
            return $this->render([
                'status' => 'storage_unavailable',
                'hours' => $hours,
                'write' => $write,
                'task_orchestration_backfill' => [
                    'available' => false,
                    'schema_version' => 'atlas.task_orchestration_backfill_receipts.v1',
                    'tables' => [
                        'atlas_tasks' => Schema::hasTable('atlas_tasks'),
                        'atlas_task_events' => Schema::hasTable('atlas_task_events'),
                    ],
                    'writes' => false,
                ],
            ]);
        }

        $events = AtlasTaskEvent::query()
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('task_id')
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->get();
        $tasks = AtlasTask::query()->whereIn('id', $events->pluck('task_id')->unique()->all())->get()->keyBy('id');
        $repairs = $this->repairs($events, $tasks);

        if ($write && $repairs->isNotEmpty()) {
            DB::transaction(function () use ($repairs): void {
                foreach ($repairs as $repair) {
                    AtlasTaskEvent::query()
                        ->whereKey($repair['event_id'])
                        ->update(['payload' => $repair['payload']]);
                }
            });
        }

        return $this->render([
            'status' => 'ok',
            'hours' => $hours,
            'write' => $write,
            'task_orchestration_backfill' => [
                'available' => true,
                'schema_version' => 'atlas.task_orchestration_backfill_receipts.v1',
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'event_count' => $events->count(),
                'task_count' => $events->pluck('task_id')->unique()->count(),
                'repair_count' => $repairs->count(),
                'repaired_event_ids' => $repairs->pluck('event_id')->values()->all(),
                'dry_run' => ! $write,
                'writes' => $write,
                'rules' => [
                    'provider_dispatch_allowed' => false,
                    'runtime_execution_allowed' => false,
                    'agent_control_plane_allowed' => false,
                    'policy_mutation_allowed' => false,
                    'auto_completion_allowed' => false,
                    'operator_review_required_for_external_execution' => true,
                ],
            ],
        ]);
    }

    /**
     * @param  Collection<int,AtlasTaskEvent>  $events
     * @param  Collection<string,AtlasTask>  $tasks
     * @return Collection<int,array{event_id:string,payload:array<string,mixed>}>
     */
    private function repairs(Collection $events, Collection $tasks): Collection
    {
        $repairs = collect();

        foreach ($events->groupBy('task_id') as $taskId => $taskEvents) {
            $previousEventId = null;
            $previousEventHash = null;
            $sequence = 0;
            $task = $tasks->get((string) $taskId);

            foreach ($taskEvents as $event) {
                $sequence++;
                $payload = $this->payloadForEvent($event, $task, $sequence, $previousEventId, $previousEventHash);
                $eventHash = $this->stableTaskEventHash((string) $event->task_id, (string) $event->event_type, (string) $event->source, $payload);
                $payload['event_hash'] = $eventHash;

                if ($payload !== (is_array($event->payload) ? $event->payload : [])) {
                    $repairs->push([
                        'event_id' => (string) $event->id,
                        'payload' => $payload,
                    ]);
                }

                $previousEventId = (string) $event->id;
                $previousEventHash = $eventHash;
            }
        }

        return $repairs;
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadForEvent(AtlasTaskEvent $event, ?AtlasTask $task, int $sequence, ?string $previousEventId, ?string $previousEventHash): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $existingBackfilledAt = data_get($payload, 'orchestration_receipt.backfilled_at');
        unset($payload['event_hash']);

        return [
            ...$payload,
            'schema_version' => 'atlas.task_orchestration.event.v1',
            'event_sequence' => $sequence,
            'previous_event_id' => $previousEventId,
            'previous_event_hash' => $previousEventHash,
            'orchestration_receipt' => [
                ...(is_array($payload['orchestration_receipt'] ?? null) ? $payload['orchestration_receipt'] : []),
                'schema_version' => 'atlas.task_orchestration.local_event_receipt.v1',
                'task_id' => (string) $event->task_id,
                'event_type' => (string) $event->event_type,
                'source' => (string) $event->source,
                'event_sequence' => $sequence,
                'hash_algorithm' => 'sha256',
                'task_status' => $task?->status,
                'task_domain' => $task?->domain,
                'source_capture_linked' => $task?->source_capture_id !== null,
                'provider_dispatch_allowed' => false,
                'runtime_execution_allowed' => false,
                'agent_control_plane_allowed' => false,
                'policy_mutation_allowed' => false,
                'auto_completion_allowed' => false,
                'payload_api_only' => true,
                'operator_review_required_for_external_execution' => true,
                'backfilled_at' => is_string($existingBackfilledAt) && $existingBackfilledAt !== ''
                    ? $existingBackfilledAt
                    : now()->toJSON(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableTaskEventHash(string $taskId, string $eventType, string $source, array $payload): string
    {
        unset($payload['event_hash']);

        return hash('sha256', $this->stableJson([
            'task_id' => $taskId,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
        ]));
    }

    private function stableJson(mixed $value): string
    {
        return json_encode($this->stableJsonValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
    }

    private function stableJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $nested) {
                $value[$key] = $this->stableJsonValue($nested);
            }
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['task_orchestration_backfill'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Task Receipt Backfill</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Dry run', ((bool) ($report['dry_run'] ?? true)) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Events', (string) ($report['event_count'] ?? 0));
        $this->components->twoColumnDetail('Repairs', (string) ($report['repair_count'] ?? 0));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
