<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AiObservabilityController extends Controller
{
    // Observability é monitoring, não auditoria — pode atrasar 30s
    // sem prejuízo. Antes: 15+ COUNT/GROUP BY por chamada × clientes
    // monitorando a cada 10s = ~2 queries/s só de observability.
    private const CACHE_TTL_SECONDS = 30;

    public function __invoke(
        Request $request,
        AiTelemetryScorecardService $scorecards,
        AiTelemetryHealthService $health,
        AtlasLedgerReplayService $ledgerReplay,
        AtlasSelfImprovementScheduleService $selfImprovementSchedule,
        AtlasAiDomainCatalogService $domainCatalog,
        AtlasAiArchitectureValidationService $architectureValidation,
        KernelReplayReportInput $replayInput,
        ProviderPerformanceProjection $providerPerformance,
        LedgerProjectionRegistry $ledgerProjectionRegistry,
        AtlasArchitectureOperationsCatalog $architectureOperations,
    ): JsonResponse {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS],
        ]);

        $hours = $replayInput->hours($data['hours'] ?? null);
        $payload = Cache::remember(
            'atlas.ai.observability:hours='.$hours,
            self::CACHE_TTL_SECONDS,
            function () use ($hours, $scorecards, $health, $ledgerReplay, $selfImprovementSchedule, $domainCatalog, $architectureValidation, $providerPerformance, $ledgerProjectionRegistry, $architectureOperations): array {
                $since = now()->subHours($hours);
                $scheduledSelfImprovement = $selfImprovementSchedule->schedulePlan();
                $domainCatalogPayload = $domainCatalog->inspect();
                $kernelSlo = $ledgerReplay->sloReportForWindow($since);
                $kernelRepair = $ledgerReplay->repairReportForWindow($since);
                $kernelPipeline = $ledgerReplay->kernelPipelineReportForWindow($since);
                $inboxActions = $ledgerReplay->inboxActionReportForWindow($since);
                $selfImprovementScheduleReplay = $ledgerReplay->selfImprovementScheduleReportForWindow($since);
                $providerPerformanceReport = $providerPerformance->reportForWindow($since);
                $ledgerProjectionHealth = $ledgerProjectionRegistry->healthReport();
                $architecturePayload = $architectureValidation->payload();

                return [
                    'window' => [
                        'since' => $since->toJSON(),
                        'until' => now()->toJSON(),
                    ],
                    'threads' => [
                        'active' => AiThread::query()->where('status', 'active')->count(),
                        'atlas_cli' => AiThread::query()->where('surface', 'atlas_cli')->count(),
                    ],
                    'traces' => [
                        'total' => AiTrace::query()->where('created_at', '>=', $since)->count(),
                        'by_status' => $this->countsBy(AiTrace::query()->where('created_at', '>=', $since), 'status'),
                        'by_provider' => $this->countsBy(AiTrace::query()->where('created_at', '>=', $since), 'provider'),
                    ],
                    'jobs' => [
                        'queued' => AiJob::query()->where('status', 'queued')->count(),
                        'processing' => AiJob::query()->where('status', 'processing')->count(),
                        'failed_24h' => AiJob::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                    ],
                    'quality' => $this->quality($since),
                    'metrics' => $scorecards->build($since),
                    'metrics_health' => $health->evaluate($since),
                    'kernel_slo' => $kernelSlo,
                    'kernel_repair' => $kernelRepair,
                    'kernel_pipeline' => $kernelPipeline,
                    'inbox_actions' => $inboxActions,
                    'provider_performance' => $providerPerformanceReport,
                    'ledger_projection_health' => $ledgerProjectionHealth,
                    'self_improvement_schedule_replay' => $selfImprovementScheduleReplay,
                    'domain_catalog' => [
                        'status' => $domainCatalogPayload['status'] ?? 'unknown',
                        'source' => $domainCatalogPayload['source'] ?? 'unknown',
                        'summary' => $domainCatalogPayload['summary'] ?? [],
                        'validation' => $domainCatalogPayload['validation'] ?? ['valid' => false],
                    ],
                    'architecture_validation' => $this->architectureValidationSummary($architecturePayload),
                    'architecture_operations' => $architectureOperations->summary(),
                    'self_improvement_schedule' => $scheduledSelfImprovement,
                    'actions' => $this->actions(),
                ];
            },
        );

        return response()->json($payload);
    }

    private function quality($since): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
            return ['available' => false];
        }

        $averageScore = AiQualityEvaluation::query()
            ->where('created_at', '>=', $since)
            ->avg('score');

        return [
            'available' => true,
            'total' => AiQualityEvaluation::query()->where('created_at', '>=', $since)->count(),
            'average_score' => $averageScore === null ? null : round((float) $averageScore, 2),
            'by_status' => $this->countsBy(AiQualityEvaluation::query()->where('created_at', '>=', $since), 'status'),
            'recent_needs_review' => AiQualityEvaluation::query()
                ->whereIn('status', ['needs_review', 'failed'])
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'trace_id', 'thread_id', 'provider', 'score', 'status', 'flags', 'created_at'])
                ->map(fn (AiQualityEvaluation $evaluation): array => [
                    'id' => $evaluation->id,
                    'trace_id' => $evaluation->trace_id,
                    'thread_id' => $evaluation->thread_id,
                    'provider' => $evaluation->provider,
                    'score' => $evaluation->score,
                    'status' => $evaluation->status,
                    'flags' => collect($evaluation->flags)->pluck('code')->values()->all(),
                    'created_at' => $evaluation->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function actions(): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_actions')) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'by_status' => $this->countsBy(AiQualityAction::query(), 'status'),
            'open' => AiQualityAction::query()->whereIn('status', ['queued', 'running', 'blocked', 'failed'])->count(),
            'recent' => AiQualityAction::query()
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'trace_id', 'remediation_trace_id', 'action_type', 'status', 'priority', 'reason', 'created_at'])
                ->map(fn (AiQualityAction $action): array => [
                    'id' => $action->id,
                    'trace_id' => $action->trace_id,
                    'remediation_trace_id' => $action->remediation_trace_id,
                    'action_type' => $action->action_type,
                    'status' => $action->status,
                    'priority' => $action->priority,
                    'reason' => $action->reason,
                    'created_at' => $action->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function architectureValidationSummary(array $payload): array
    {
        return [
            'status' => $payload['status'] ?? 'unknown',
            'schema_version' => $payload['schema_version'] ?? null,
            'validated_at' => $payload['validated_at'] ?? null,
            'kernel' => [
                'valid' => (bool) data_get($payload, 'kernel.valid', false),
                'static_scan' => [
                    'valid' => (bool) data_get($payload, 'kernel.static_scan.valid', false),
                    'summary' => data_get($payload, 'kernel.static_scan.summary', []),
                ],
            ],
            'capabilities' => [
                'valid' => (bool) data_get($payload, 'capabilities.valid', false),
                'count' => (int) data_get($payload, 'capabilities.count', 0),
                'surface_count' => (int) data_get($payload, 'capabilities.surface_count', 0),
            ],
            'domains' => [
                'valid' => (bool) data_get($payload, 'domains.valid', false),
                'domain_count' => (int) data_get($payload, 'domains.domain_count', 0),
                'flow_count' => (int) data_get($payload, 'domains.flow_count', 0),
            ],
            'orchestrators' => [
                'valid' => (bool) data_get($payload, 'orchestrators.valid', false),
                'count' => (int) data_get($payload, 'orchestrators.count', 0),
            ],
            'onboarding' => $payload['onboarding'] ?? [],
        ];
    }

    private function countsBy($query, string $column): array
    {
        return $query
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->get()
            ->mapWithKeys(fn ($row): array => [($row->{$column} ?: 'unknown') => (int) $row->aggregate])
            ->all();
    }
}
