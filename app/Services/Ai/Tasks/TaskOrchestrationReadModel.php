<?php

namespace App\Services\Ai\Tasks;

use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TaskOrchestrationReadModel
{
    public const SCHEMA_VERSION = 'atlas.task_orchestration_report.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subHours(24);
        $until ??= now();
        $tables = $this->tables();

        if (! $tables['atlas_tasks'] || ! $tables['atlas_task_events']) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'tables' => $tables,
                'status' => 'storage_unavailable',
                'writes' => false,
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'reasons' => ['task_orchestration_tables_missing'],
                    'recommended_action' => 'run_task_orchestration_migrations',
                ],
            ];
        }

        $tasks = AtlasTask::query()->latest()->get();
        $events = AtlasTaskEvent::query()
            ->whereBetween('occurred_at', [$since, $until])
            ->latest('occurred_at')
            ->get();
        $summary = $this->summary($tasks, $events);
        $reviewSignal = $this->reviewSignal($summary);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'tables' => $tables,
            'status' => $reviewSignal['status'] === 'ok' ? 'ok' : 'warning',
            ...$summary,
            'review_signal' => $reviewSignal,
            'handoff_contract' => $this->handoffContract(),
            'recent_events' => $events->take(10)->map(fn (AtlasTaskEvent $event): array => $this->eventPayload($event))->values()->all(),
            'sample_tasks' => $tasks->take(10)->map(fn (AtlasTask $task): array => $this->taskPayload($task))->values()->all(),
            'writes' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function tables(): array
    {
        return [
            'atlas_tasks' => Schema::hasTable('atlas_tasks'),
            'atlas_task_events' => Schema::hasTable('atlas_task_events'),
        ];
    }

    /**
     * @param  Collection<int,AtlasTask>  $tasks
     * @param  Collection<int,AtlasTaskEvent>  $events
     * @return array<string,mixed>
     */
    private function summary(Collection $tasks, Collection $events): array
    {
        $taskIdsWithEvents = $events->pluck('task_id')->unique();
        $receiptEvents = $events->filter(
            fn (AtlasTaskEvent $event): bool => data_get($event->payload, 'orchestration_receipt.schema_version') === 'atlas.task_orchestration.local_event_receipt.v1'
        );
        $hashedEvents = $events->filter(fn (AtlasTaskEvent $event): bool => is_string(data_get($event->payload, 'event_hash')));
        $sequencedEvents = $events->filter(fn (AtlasTaskEvent $event): bool => data_get($event->payload, 'event_sequence') !== null);
        $unsafeEvents = $events->filter(fn (AtlasTaskEvent $event): bool => $this->hasUnsafeEventReceipt($event));
        $incompleteReceiptEvents = $receiptEvents->filter(fn (AtlasTaskEvent $event): bool => $this->hasIncompleteEventReceipt($event));
        $externalReadyTasks = $tasks->filter(fn (AtlasTask $task): bool => $this->requiresExternalReview($task));

        return [
            'task_count' => $tasks->count(),
            'open_task_count' => $tasks->where('status', 'open')->count(),
            'blocked_task_count' => $tasks->where('status', 'blocked')->count(),
            'completed_task_count' => $tasks->where('status', 'completed')->count(),
            'domain_counts' => $tasks->pluck('domain')->filter()->countBy()->all(),
            'status_counts' => $tasks->pluck('status')->filter()->countBy()->all(),
            'planning_status_counts' => $tasks->pluck('planning_status')->filter()->countBy()->all(),
            'event_count' => $events->count(),
            'task_with_event_count' => $taskIdsWithEvents->count(),
            'receipt_event_count' => $receiptEvents->count(),
            'missing_receipt_event_count' => $events->count() - $receiptEvents->count(),
            'hashed_event_count' => $hashedEvents->count(),
            'missing_hash_event_count' => $events->count() - $hashedEvents->count(),
            'sequenced_event_count' => $sequencedEvents->count(),
            'missing_sequence_event_count' => $events->count() - $sequencedEvents->count(),
            'unsafe_event_receipt_count' => $unsafeEvents->count(),
            'incomplete_event_receipt_count' => $incompleteReceiptEvents->count(),
            'event_type_counts' => $events->pluck('event_type')->filter()->countBy()->all(),
            'source_counts' => $events->pluck('source')->filter()->countBy()->all(),
            'external_review_required_task_count' => $externalReadyTasks->count(),
        ];
    }

    private function hasUnsafeEventReceipt(AtlasTaskEvent $event): bool
    {
        $receipt = (array) data_get($event->payload, 'orchestration_receipt', []);

        if ($receipt === []) {
            return false;
        }

        return data_get($receipt, 'provider_dispatch_allowed') === true
            || data_get($receipt, 'runtime_execution_allowed') === true
            || data_get($receipt, 'agent_control_plane_allowed') === true
            || data_get($receipt, 'policy_mutation_allowed') === true
            || data_get($receipt, 'auto_completion_allowed') === true
            || data_get($receipt, 'operator_review_required_for_external_execution') === false;
    }

    private function hasIncompleteEventReceipt(AtlasTaskEvent $event): bool
    {
        $receipt = (array) data_get($event->payload, 'orchestration_receipt', []);

        if ($receipt === []) {
            return false;
        }

        foreach ([
            'provider_dispatch_allowed',
            'runtime_execution_allowed',
            'agent_control_plane_allowed',
            'policy_mutation_allowed',
            'auto_completion_allowed',
            'operator_review_required_for_external_execution',
        ] as $field) {
            if (! array_key_exists($field, $receipt)) {
                return true;
            }
        }

        return false;
    }

    private function requiresExternalReview(AtlasTask $task): bool
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        return data_get($metadata, 'engineering_contract') !== null
            || data_get($metadata, 'latest_engineering_run') !== null
            || in_array($task->execution_mode, ['provider', 'runtime', 'agent_handoff'], true);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary): array
    {
        if ((int) ($summary['unsafe_event_receipt_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['unsafe_task_orchestration_event_receipt'],
                'recommended_action' => 'inspect_task_orchestration_event_receipts',
            ];
        }

        if ((int) ($summary['missing_receipt_event_count'] ?? 0) > 0 || (int) ($summary['missing_hash_event_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['task_events_missing_orchestration_receipt_or_hash'],
                'recommended_action' => 'refresh_task_events_through_task_planning_service',
            ];
        }

        if ((int) ($summary['incomplete_event_receipt_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['task_events_have_legacy_incomplete_orchestration_receipts'],
                'recommended_action' => 'refresh_task_events_through_task_planning_service',
            ];
        }

        if ((int) ($summary['external_review_required_task_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['tasks_require_external_execution_review'],
                'recommended_action' => 'review_task_execution_receipts_before_provider_or_runtime_handoff',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reasons' => [],
            'recommended_action' => 'continue_task_orchestration_monitoring',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function handoffContract(): array
    {
        $contract = [
            'schema_version' => 'atlas.task_orchestration.handoff_contract.v1',
            'status' => 'declared_fail_closed',
            'purpose' => 'task_agent_orchestration_boundary',
            'task_event_receipt_required' => true,
            'event_hash_required' => true,
            'event_sequence_required' => true,
            'provider_dispatch_allowed_by_report' => false,
            'runtime_execution_allowed_by_report' => false,
            'agent_control_plane_allowed_by_report' => false,
            'policy_mutation_allowed_by_report' => false,
            'auto_completion_allowed_by_report' => false,
            'operator_review_required_for_external_execution' => true,
            'required_before_provider_runtime_or_agent_handoff' => [
                'atlas.task_orchestration.local_event_receipt.v1',
                'event_hash',
                'event_sequence',
                'decision_receipt_hash',
                'operator_review_for_external_execution',
                'runtime_invocation_contract_when_runtime_is_needed',
                'evidence_ledger_ref',
            ],
            'raw_task_title_persisted_in_report' => false,
            'raw_task_description_persisted_in_report' => false,
            'writes' => false,
        ];

        return [
            ...$contract,
            'contract_hash' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventPayload(AtlasTaskEvent $event): array
    {
        return [
            'id' => $event->id,
            'task_id' => $event->task_id,
            'event_type' => $event->event_type,
            'source' => $event->source,
            'event_sequence' => data_get($event->payload, 'event_sequence'),
            'has_event_hash' => data_get($event->payload, 'event_hash') !== null,
            'receipt_schema_version' => data_get($event->payload, 'orchestration_receipt.schema_version'),
            'occurred_at' => $event->occurred_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AtlasTask $task): array
    {
        return [
            'id' => $task->id,
            'title_hash' => hash('sha256', (string) $task->title),
            'status' => $task->status,
            'priority' => $task->priority,
            'domain' => $task->domain,
            'planning_status' => $task->planning_status,
            'execution_mode' => $task->execution_mode,
            'source_capture_linked' => $task->source_capture_id !== null,
            'created_at' => $task->created_at?->toJSON(),
            'updated_at' => $task->updated_at?->toJSON(),
        ];
    }
}
