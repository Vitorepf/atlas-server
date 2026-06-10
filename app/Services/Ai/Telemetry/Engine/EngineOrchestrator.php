<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiPerformanceReportRun;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\ReportPayload;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pipeline orchestrator. Wires Layers 2-6 in sequence with per-layer try/catch
 * isolation. On layer failure, the run is marked 'partial' (not 'failed') and
 * the assembled report still emits — graceful degradation.
 *
 * Run lifecycle:
 *   1. INSERT ai_performance_report_runs row with status='started'
 *   2. Run layers in order (Trust → Statistical → Diagnostic → Recommendation → Assembly)
 *   3. UPDATE row with status, duration_ms, layer_timings, layer_errors
 *
 * Per the Conductor design: feature flag (atlas.report.engine_version) gates
 * whether this orchestrator runs at all. When 'legacy', caller skips.
 */
class EngineOrchestrator
{
    public function __construct(
        private readonly TrustGateService $trust,
        private readonly StatisticalAnalysisService $statistical,
        private readonly DiagnosticAttributionService $diagnostic,
        private readonly RecommendationLifecycleService $recommendation,
        private readonly ReportAssemblerService $assembler,
    ) {}

    public function execute(ReportContext $ctx, WindowAggregates $aggregates): ReportPayload
    {
        $inputSnapshot = $this->inputSnapshot($aggregates);
        $run = $this->openRun($ctx, $inputSnapshot);
        $timings = [];
        $errors = [];
        $startedAt = microtime(true);

        // Layer 2 — Trust Gate
        $t0 = microtime(true);
        try {
            $trustResult = $this->trust->evaluate($ctx, $aggregates, $run?->id);
        } catch (Throwable $e) {
            $errors['trust'] = substr($e->getMessage(), 0, 500);
            $trustResult = \App\Services\Ai\Telemetry\Engine\Dto\TrustResult::skipped('layer_exception');
        }
        $timings['trust_ms'] = (int) ((microtime(true) - $t0) * 1000);

        // Layer 3 — Statistical
        $t0 = microtime(true);
        try {
            $statisticalResult = $this->statistical->analyze($ctx, $aggregates, $trustResult);
        } catch (Throwable $e) {
            $errors['statistical'] = substr($e->getMessage(), 0, 500);
            $statisticalResult = \App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult::empty('layer_exception');
        }
        $timings['statistical_ms'] = (int) ((microtime(true) - $t0) * 1000);

        // Layer 4 — Diagnostic
        $t0 = microtime(true);
        try {
            $diagnosticResult = $this->diagnostic->diagnose(
                $ctx,
                $aggregates,
                $this->baselineAggregates($aggregates),
                $statisticalResult,
                $trustResult,
                $run?->id,
            );
        } catch (Throwable $e) {
            $errors['diagnostic'] = substr($e->getMessage(), 0, 500);
            $diagnosticResult = \App\Services\Ai\Telemetry\Engine\Dto\DiagnosticResult::empty('layer_exception');
        }
        $timings['diagnostic_ms'] = (int) ((microtime(true) - $t0) * 1000);

        // Layer 5 — Recommendations
        $t0 = microtime(true);
        try {
            $recommendationResult = $this->recommendation->recommend($ctx, $diagnosticResult, $statisticalResult);
        } catch (Throwable $e) {
            $errors['recommendation'] = substr($e->getMessage(), 0, 500);
            $recommendationResult = new \App\Services\Ai\Telemetry\Engine\Dto\RecommendationResult();
        }
        $timings['recommendation_ms'] = (int) ((microtime(true) - $t0) * 1000);

        // Layer 6 — Assembly
        $t0 = microtime(true);
        $payload = $this->assembler->assemble(
            $ctx, $aggregates, $trustResult, $statisticalResult, $diagnosticResult, $recommendationResult, $run?->id,
        );
        $timings['assembly_ms'] = (int) ((microtime(true) - $t0) * 1000);

        $totalMs = (int) ((microtime(true) - $startedAt) * 1000);
        $this->closeRun($run, $payload, $trustResult, $diagnosticResult, $recommendationResult, $totalMs, $timings, $errors);

        return $payload;
    }

    private function openRun(ReportContext $ctx, array $inputSnapshot): ?AiPerformanceReportRun
    {
        if ($ctx->runMode === 'dry_run') {
            return null;
        }

        if (! DatabaseTableAvailability::has('ai_performance_report_runs')) {
            return null;
        }
        try {
            $values = [
                'report_date' => $ctx->windowStart->toDateString(),
                'report_type' => $ctx->reportType,
                'engine_version' => $ctx->engineVersion,
                'run_mode' => $ctx->runMode,
                'status' => 'started',
                'started_at' => $ctx->clock,
            ];

            if (DatabaseTableAvailability::hasColumn('ai_performance_report_runs', 'input_hash')) {
                $values['input_hash'] = $this->hash($inputSnapshot);
            }
            if (DatabaseTableAvailability::hasColumn('ai_performance_report_runs', 'input_snapshot')) {
                $values['input_snapshot'] = $inputSnapshot;
            }

            return AiPerformanceReportRun::query()->create($values);
        } catch (Throwable $e) {
            Log::warning('EngineOrchestrator failed to open run row', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function baselineAggregates(WindowAggregates $aggregates): WindowAggregates
    {
        return new WindowAggregates(
            scorecard: [],
            health: [],
            summary: [],
            summaries: collect(),
            aggregatorVersion: $aggregates->aggregatorVersion,
            hasMixedAggregatorVersions: $aggregates->hasMixedAggregatorVersions,
        );
    }

    private function closeRun(
        ?AiPerformanceReportRun $run,
        ReportPayload $payload,
        $trust,
        $diagnostic,
        $recommendation,
        int $durationMs,
        array $timings,
        array $errors,
    ): void {
        if ($run === null) {
            return;
        }
        try {
            $payloadSnapshot = $payload->toArray();
            $values = [
                'status' => empty($errors) ? 'completed' : 'partial',
                'completed_at' => CarbonImmutable::now(),
                'duration_ms' => $durationMs,
                'traces_processed' => data_get($run->input_snapshot, 'summary.traces'),
                'trust_score' => $trust->trustScore ?? null,
                'finding_count' => count($diagnostic->findings ?? []),
                'recommendation_count' => count($recommendation->created ?? []),
                'layer_timings' => $timings,
                'layer_errors' => $errors,
                'schema_version' => $payload->schemaVersion(),
            ];

            if (DatabaseTableAvailability::hasColumn('ai_performance_report_runs', 'output_hash')) {
                $values['output_hash'] = $this->hash($payloadSnapshot);
            }
            if (DatabaseTableAvailability::hasColumn('ai_performance_report_runs', 'output_snapshot')) {
                $values['output_snapshot'] = $payloadSnapshot;
            }

            $run->update($values);
        } catch (Throwable $e) {
            Log::warning('EngineOrchestrator failed to close run row', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function inputSnapshot(WindowAggregates $aggregates): array
    {
        return [
            'scorecard' => $aggregates->scorecard,
            'health' => $aggregates->health,
            'summary' => $aggregates->summary,
            'aggregator_version' => $aggregates->aggregatorVersion,
            'mixed_aggregator_versions' => $aggregates->hasMixedAggregatorVersions,
            'summaries' => $aggregates->summaries
                ->map(fn ($summary): array => collect($summary->getAttributes())
                    ->only([
                        'trace_id', 'surface', 'runtime', 'provider', 'model', 'agent_slug', 'task_type',
                        'status', 'app_send_to_visible_ms', 'provider_latency_ms', 'total_latency_ms',
                        'cost_microusd', 'cost_confidence', 'cost_mode', 'context_efficiency_score',
                        'auto_quality_score', 'continuity_score', 'human_feedback_score', 'outcome_score',
                        'remediation_score', 'final_quality_score', 'final_efficiency_score',
                        'first_pass_success', 'needed_remediation', 'reask_detected',
                        'provider_switched_after_response', 'router_mode', 'router_selected_provider',
                        'score_components', 'metadata', 'computed_at',
                    ])
                    ->all())
                ->values()
                ->all(),
        ];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $entry): mixed => $this->sortRecursive($entry), $value);
        }

        ksort($value);

        return array_map(fn (mixed $entry): mixed => $this->sortRecursive($entry), $value);
    }
}
