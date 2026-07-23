<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Services\Ai\Kernel\Decision\DynamicComputeMarketReportService;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;

/**
 * GOD-DEBULK D3: replay/report tool family relocated verbatim from
 * AtlasOpenBrainMcpService so the ~1089-LOC public tools() schema + dispatch can
 * stay on the façade under 2000 LOC. Every handler here is read-only
 * (`writes => false`); bodies are byte-identical to the pre-split service (only
 * dispatched-handler visibility private->public). The façade dispatch delegates
 * here; the scanner source-pins for these handlers were relocated to this file
 * under GOD-DEBULK D3 with their str_contains strength unchanged.
 */
class ReportTools
{
    public function __construct(
        private readonly AtlasLedgerReplayService $ledgerReplay,
        private readonly ProviderPerformanceProjection $providerPerformance,
        private readonly DynamicComputeMarketReportService $dynamicComputeMarketReports,
        private readonly LedgerProjectionRegistry $ledgerProjectionRegistry,
        private readonly KernelReplayReportInput $replayInput,
        private readonly AtlasSelfImprovementScheduleService $selfImprovementSchedule,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function selfImprovementSchedule(array $arguments): array
    {
        $detail = $this->string($arguments['detail'] ?? null) ?: 'health';
        if (! in_array($detail, ['health', 'plan', 'commands'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_self_improvement_schedule',
                'error' => 'invalid_detail',
                'allowed_detail' => ['health', 'plan', 'commands'],
            ];
        }

        $plan = $this->selfImprovementSchedule->schedulePlan();
        $health = $this->selfImprovementSchedule->scheduleHealth();
        $scheduledCommands = $this->selfImprovementSchedule->scheduledCommands();

        return [
            'ok' => $health['health']['status'] !== 'warning',
            'tool' => 'atlas_self_improvement_schedule',
            'detail' => $detail,
            'schedule' => match ($detail) {
                'plan' => $plan,
                'commands' => [
                    'schema_version' => $plan['schema_version'],
                    'status' => $plan['status'],
                    'enabled' => $plan['enabled'],
                    'schedulable' => $plan['schedulable'],
                    'scheduler_registration' => $plan['scheduler_registration'],
                    'timezone' => $plan['timezone'],
                    'plan_hash' => $plan['plan_hash'],
                    'plan_hash_algorithm' => $plan['plan_hash_algorithm'],
                    'commands' => $scheduledCommands,
                    'count' => count($scheduledCommands),
                    'cadence_counts' => $plan['cadence_counts'],
                ],
                default => $health,
            },
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function selfImprovementScheduleReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $report = $this->ledgerReplay->selfImprovementScheduleReportForWindow(now()->subHours($hours));

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_self_improvement_schedule_report',
            'hours' => $hours,
            'self_improvement_schedule_replay' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function kernelSloReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->kernelSloFilters($arguments);
        $report = $this->ledgerReplay->sloReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_kernel_slo_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_slo' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function kernelPipelineReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'surface_id',
            'flow',
            'input_mode',
            'surface_contract_source',
            'emitter_stage',
        ]);
        $report = $this->ledgerReplay->kernelPipelineReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_kernel_pipeline_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_pipeline' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function repairLoopReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'strategy',
            'failure_domain',
            'emitter_stage',
        ]);
        $report = $this->ledgerReplay->repairReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_repair_loop_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_repair' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function inboxActionReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'action',
            'actor_type',
            'inbox_item_category',
            'inbox_item_severity',
            'recommended_action',
            'source_type',
        ]);
        $report = $this->ledgerReplay->inboxActionReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_inbox_action_report',
            'hours' => $hours,
            'filters' => $filters,
            'inbox_actions' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function agentBehaviorReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'provider',
            'model',
            'agent_slug',
            'finding_code',
            'contract_id',
        ]);
        $report = $this->ledgerReplay->agentBehaviorReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_agent_behavior_report',
            'hours' => $hours,
            'filters' => $filters,
            'agent_behavior' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function ledgerProjectionHealth(array $arguments): array
    {
        $maxLagSeconds = $this->positiveInt($arguments['max_lag_seconds'] ?? null);
        $report = $this->ledgerProjectionRegistry->healthReport($maxLagSeconds);

        return [
            'ok' => (bool) ($report['available'] ?? false) && ($report['status'] ?? null) !== 'critical',
            'tool' => 'atlas_ledger_projection_health',
            'max_lag_seconds' => $report['max_lag_seconds'] ?? $maxLagSeconds,
            'ledger_projection_health' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function providerPerformanceReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'provider_cli',
            'provider',
            'domain',
            'flow',
            'task_type',
            'specialist_profile',
            'risk',
            'selection_mode',
        ]);
        if (isset($filters['provider']) && ! isset($filters['provider_cli'])) {
            $filters['provider_cli'] = $filters['provider'];
        }
        unset($filters['provider']);

        $report = $this->providerPerformance->reportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_provider_performance_report',
            'hours' => $hours,
            'filters' => $filters,
            'provider_performance' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function dynamicComputeMarketReport(array $arguments): array
    {
        $payload = $this->dynamicComputeMarketReports->report($this->onlyScalarFilters($arguments, [
            'provider',
            'model',
            'domain',
            'flow',
            'task_type',
            'specialist_profile',
        ]));

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_dynamic_compute_market_report',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function decisionReceiptReport(array $arguments): array
    {
        $envelopeId = $this->string($arguments['envelope'] ?? ($arguments['envelope_id'] ?? null));
        if ($envelopeId === null || $envelopeId === '') {
            return [
                'ok' => false,
                'tool' => 'atlas_decision_receipt_report',
                'error' => 'envelope_required',
                'writes' => false,
            ];
        }

        return [
            'ok' => true,
            'tool' => 'atlas_decision_receipt_report',
            'decision_receipt_replay' => $this->ledgerReplay->decisionReceiptReportForEnvelope($envelopeId),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function reportWindowHours(array $arguments): int
    {
        return $this->replayInput->hours($arguments['hours'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,string>
     */
    private function kernelSloFilters(array $arguments): array
    {
        return $this->onlyScalarFilters($arguments, ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id']);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<int,string>  $allowed
     * @return array<string,string>
     */
    private function onlyScalarFilters(array $arguments, array $allowed): array
    {
        return $this->replayInput->scalarFilters($arguments, $allowed);
    }

    // ponytail: string/positiveInt copied verbatim from the façade (which keeps its
    // own pinned copies) — matches the existing per-Tools-class primitive convention.

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
