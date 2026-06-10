<?php

namespace App\Services\Ai\Scheduling;

use App\Models\AiScheduledTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class LongRunningWorkReadModel
{
    public const SCHEMA_VERSION = 'atlas.long_running_work_report.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subHours(24);
        $until ??= now();
        $tableExists = DatabaseTableAvailability::has('ai_scheduled_tasks');

        if (! $tableExists) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'tables' => ['ai_scheduled_tasks' => false],
                'status' => 'storage_unavailable',
                'writes' => false,
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'reasons' => ['scheduled_tasks_table_missing'],
                    'recommended_action' => 'run_scheduler_migrations',
                ],
            ];
        }

        $tasks = AiScheduledTask::query()->latest()->get();
        $recentlyRun = $tasks
            ->filter(fn (AiScheduledTask $task): bool => $task->last_run_at !== null && $task->last_run_at->betweenIncluded($since, $until))
            ->values();
        $dueTasks = $tasks
            ->filter(fn (AiScheduledTask $task): bool => $this->isDue($task, $until))
            ->values();
        $summary = $this->summary($tasks, $recentlyRun, $dueTasks);
        $reviewSignal = $this->reviewSignal($summary);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'tables' => ['ai_scheduled_tasks' => true],
            'status' => $reviewSignal['status'] === 'ok' ? 'ok' : 'warning',
            ...$summary,
            'review_signal' => $reviewSignal,
            'baseline_contract' => $this->baselineContract(),
            'baseline_schedule_declarations' => $this->baselineScheduleDeclarations($tasks),
            'due_tasks' => $dueTasks->take(10)->map(fn (AiScheduledTask $task): array => $this->taskPayload($task))->values()->all(),
            'recent_runs' => $recentlyRun->take(10)->map(fn (AiScheduledTask $task): array => $this->taskPayload($task))->values()->all(),
            'writes' => false,
        ];
    }

    private function isDue(AiScheduledTask $task, CarbonInterface $until): bool
    {
        if (! $task->enabled || $task->next_run_at === null || $task->next_run_at->greaterThan($until)) {
            return false;
        }

        return $task->repeat_remaining === null || $task->repeat_remaining > 0;
    }

    /**
     * @param  Collection<int,AiScheduledTask>  $tasks
     * @param  Collection<int,AiScheduledTask>  $recentlyRun
     * @param  Collection<int,AiScheduledTask>  $dueTasks
     * @return array<string,mixed>
     */
    private function summary(Collection $tasks, Collection $recentlyRun, Collection $dueTasks): array
    {
        $contractedRuns = $recentlyRun->filter(
            fn (AiScheduledTask $task): bool => data_get($task->metadata, 'last_run_receipt.autonomy_contract.schema_version') === 'atlas.long_running_work.autonomy_contract.v1'
        );
        $unsafeReceipts = $recentlyRun->filter(fn (AiScheduledTask $task): bool => $this->hasUnsafeReceipt($task));
        $baselineFamilies = collect($this->baselineFamilies());
        $declaredBaselineFamilies = $tasks
            ->map(fn (AiScheduledTask $task): mixed => data_get($task->metadata, 'baseline_schedule_declaration.family'))
            ->filter(fn (mixed $family): bool => is_string($family) && $family !== '')
            ->unique()
            ->values();
        $missingBaselineFamilies = $baselineFamilies
            ->diff($declaredBaselineFamilies)
            ->values();

        return [
            'scheduled_task_count' => $tasks->count(),
            'enabled_task_count' => $tasks->where('enabled', true)->count(),
            'disabled_task_count' => $tasks->where('enabled', false)->count(),
            'baseline_schedule_declaration_count' => $declaredBaselineFamilies->count(),
            'baseline_schedule_missing_family_count' => $missingBaselineFamilies->count(),
            'baseline_schedule_missing_families' => $missingBaselineFamilies->all(),
            'due_task_count' => $dueTasks->count(),
            'overdue_task_count' => $dueTasks->filter(fn (AiScheduledTask $task): bool => $task->next_run_at?->lessThan(now()->subMinutes(15)) ?? false)->count(),
            'recent_run_count' => $recentlyRun->count(),
            'failed_recent_run_count' => $recentlyRun->filter(fn (AiScheduledTask $task): bool => in_array($task->last_status, ['failed', 'timeout'], true))->count(),
            'last_status_counts' => $recentlyRun->pluck('last_status')->filter()->countBy()->all(),
            'kind_counts' => $tasks->pluck('kind')->filter()->countBy()->all(),
            'target_platform_counts' => $tasks->pluck('target_platform')->filter()->countBy()->all(),
            'autonomy_contract_count' => $contractedRuns->count(),
            'missing_autonomy_contract_count' => $recentlyRun->count() - $contractedRuns->count(),
            'unsafe_autonomy_receipt_count' => $unsafeReceipts->count(),
        ];
    }

    private function hasUnsafeReceipt(AiScheduledTask $task): bool
    {
        $contract = (array) data_get($task->metadata, 'last_run_receipt.autonomy_contract', []);

        if ($contract === []) {
            return false;
        }

        return data_get($contract, 'autonomy_level') !== 'low'
            || data_get($contract, 'single_run_only') !== true
            || data_get($contract, 'operator_review_required_for_escalation') !== true
            || data_get($contract, 'schedule_mutation_allowed') !== false
            || data_get($contract, 'recursive_schedule_execution_allowed') !== false
            || data_get($contract, 'autonomous_followup_allowed') !== false;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary): array
    {
        if ((int) ($summary['unsafe_autonomy_receipt_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['unsafe_long_running_work_autonomy_receipt'],
                'recommended_action' => 'inspect_scheduled_task_receipts_before_autonomy_promotion',
            ];
        }

        if ((int) ($summary['failed_recent_run_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['scheduled_task_runs_failed'],
                'recommended_action' => 'inspect_failed_scheduled_task_runs',
            ];
        }

        if ((int) ($summary['overdue_task_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['scheduled_tasks_overdue'],
                'recommended_action' => 'run_scheduler_tick_dry_run_and_check_worker',
            ];
        }

        if ((int) ($summary['recent_run_count'] ?? 0) > 0 && (int) ($summary['missing_autonomy_contract_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['scheduled_task_runs_missing_autonomy_contract'],
                'recommended_action' => 'refresh_scheduled_task_runs_with_autonomy_contract',
            ];
        }

        if ((int) ($summary['scheduled_task_count'] ?? 0) === 0) {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'reasons' => ['no_long_running_work_schedule_declared'],
                'recommended_action' => 'declare_minimal_structure_mother_schedule_plan_before_autonomy_promotion',
            ];
        }

        if ((int) ($summary['baseline_schedule_declaration_count'] ?? 0) > 0
            && (int) ($summary['baseline_schedule_missing_family_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'reasons' => ['structure_mother_baseline_schedule_incomplete'],
                'recommended_action' => 'declare_missing_structure_mother_baseline_schedule_families',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reasons' => [],
            'recommended_action' => ((int) ($summary['enabled_task_count'] ?? 0)) === 0
                ? 'review_and_enable_baseline_schedules_when_operator_ready'
                : 'continue_long_running_work_monitoring',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineContract(): array
    {
        $contract = [
            'schema_version' => 'atlas.long_running_work.baseline_contract.v1',
            'status' => 'declared_read_only',
            'purpose' => 'structure_mother_long_running_work_foundation',
            'minimum_schedule_families' => $this->baselineFamilies(),
            'execution_authority' => [
                'dispatch_allowed_by_report' => false,
                'schedule_mutation_allowed_by_report' => false,
                'autonomy_level_ceiling' => 'low',
                'operator_review_required_for_escalation' => true,
                'recursive_schedule_execution_allowed' => false,
                'autonomous_followup_allowed' => false,
            ],
            'required_receipts' => [
                'atlas.scheduled_task_run_receipt.v1',
                'atlas.long_running_work.autonomy_contract.v1',
            ],
            'required_before_promotion' => [
                'at_least_one_enabled_schedule',
                'recent_successful_run_with_autonomy_contract',
                'no_unsafe_autonomy_receipts',
                'no_overdue_tasks',
                'human_review_for_escalation',
            ],
            'raw_prompt_persisted' => false,
            'raw_output_in_metadata' => false,
            'workspace_path_exposed' => false,
            'writes' => false,
        ];

        return [
            ...$contract,
            'contract_hash' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function baselineFamilies(): array
    {
        return [
            'memory_open_brain_retrieval_snapshot',
            'capture_inbox_pipeline_review',
            'task_orchestration_receipt_review',
            'tool_action_runtime_boundary_review',
            'proactive_notification_review',
        ];
    }

    /**
     * @param  Collection<int,AiScheduledTask>  $tasks
     * @return array<int,array<string,mixed>>
     */
    private function baselineScheduleDeclarations(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (AiScheduledTask $task): bool => data_get($task->metadata, 'baseline_schedule_declaration.schema_version') === 'atlas.long_running_work.baseline_schedule_declaration.v1')
            ->sortBy(fn (AiScheduledTask $task): string => (string) data_get($task->metadata, 'baseline_schedule_declaration.family'))
            ->map(fn (AiScheduledTask $task): array => [
                'id' => $task->id,
                'family' => (string) data_get($task->metadata, 'baseline_schedule_declaration.family'),
                'enabled' => (bool) $task->enabled,
                'schedule' => $task->schedule,
                'next_run_at' => $task->next_run_at?->toJSON(),
                'dispatch_allowed' => data_get($task->metadata, 'baseline_schedule_declaration.dispatch_allowed') === true,
                'requires_operator_enablement' => data_get($task->metadata, 'baseline_schedule_declaration.requires_operator_enablement') === true,
                'declaration_hash' => (string) data_get($task->metadata, 'baseline_schedule_declaration.declaration_hash', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AiScheduledTask $task): array
    {
        return [
            'id' => $task->id,
            'title_hash' => hash('sha256', (string) $task->title),
            'kind' => $task->kind,
            'target_platform' => $task->target_platform,
            'enabled' => (bool) $task->enabled,
            'next_run_at' => $task->next_run_at?->toJSON(),
            'last_run_at' => $task->last_run_at?->toJSON(),
            'last_status' => $task->last_status,
            'repeat_remaining' => $task->repeat_remaining,
            'receipt_schema_version' => data_get($task->metadata, 'last_run_receipt.schema_version'),
            'autonomy_contract' => data_get($task->metadata, 'last_run_receipt.autonomy_contract.schema_version'),
            'autonomy_contract_summary' => $this->autonomyContractSummary($task),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function autonomyContractSummary(AiScheduledTask $task): array
    {
        $contract = (array) data_get($task->metadata, 'last_run_receipt.autonomy_contract', []);

        return [
            'schema_version' => data_get($contract, 'schema_version'),
            'contract_hash' => $contract === [] ? null : hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'present' => data_get($contract, 'schema_version') === 'atlas.long_running_work.autonomy_contract.v1',
            'unsafe' => $this->hasUnsafeReceipt($task),
            'autonomy_level' => data_get($contract, 'autonomy_level'),
            'single_run_only' => data_get($contract, 'single_run_only') === true,
            'operator_review_required_for_escalation' => data_get($contract, 'operator_review_required_for_escalation') === true,
            'schedule_mutation_allowed' => data_get($contract, 'schedule_mutation_allowed') === true,
            'recursive_schedule_execution_allowed' => data_get($contract, 'recursive_schedule_execution_allowed') === true,
            'autonomous_followup_allowed' => data_get($contract, 'autonomous_followup_allowed') === true,
        ];
    }
}
