<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticResult;
use App\Services\Ai\Telemetry\Engine\Dto\RecommendationResult;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\ReportPayload;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;

/**
 * Layer 6 — Report Assembler.
 *
 * Combines outputs from Layers 2-5 into a single ReportPayload. Owns the
 * separation between frozen (mobile-facing 6 keys) and extended (schema_v2 additive).
 *
 * Per Decisão #1: trust_gate goes to extended, NOT compact mobile.
 *                 Mobile schema_v1 clients ignore unknown extended keys.
 */
class ReportAssemblerService
{
    public function assemble(
        ReportContext $ctx,
        WindowAggregates $aggregates,
        TrustResult $trust,
        StatisticalResult $statistical,
        DiagnosticResult $diagnostic,
        RecommendationResult $recommendation,
        ?string $runId = null,
    ): ReportPayload {
        $findings = array_map(fn ($f) => $f->toArray(), $diagnostic->findings);
        $nextActions = $this->actionsFromRecommendations($recommendation);
        $risks = $this->risksFromTrust($trust, $aggregates);

        $frozen = [
            'decision' => $this->decisionFor($trust, $diagnostic, $recommendation),
            'highlights' => $this->highlightsFor($aggregates, $trust),
            'next_actions' => $nextActions,
            'risks' => $risks,
            'validation' => [
                'basis' => 'trace_created_at',
                'window_start' => $ctx->windowStart->toIso8601String(),
                'window_end' => $ctx->windowEnd->toIso8601String(),
                'aggregator_version' => $aggregates->aggregatorVersion,
            ],
            'full_text' => $this->fullTextFor($ctx, $aggregates, $trust, $diagnostic, $recommendation),
        ];

        $extended = [
            'trust_gate' => [
                'trust_score' => $trust->trustScore,
                'trust_level' => $trust->trustLevel,
                'usable_for_attribution' => $trust->usableForAttribution,
                'sample_count' => $trust->sampleCount,
                'top_gaps' => $trust->topGaps,
                'skipped' => $trust->skipped,
            ],
            'findings' => $findings,
            'recommendations' => [
                'created_count' => count($recommendation->created),
                'reaffirmed_count' => count($recommendation->reaffirmed),
                'superseded_count' => count($recommendation->superseded),
                'effectiveness_scorecard' => $recommendation->effectivenessScorecard,
            ],
            'anomalies' => $statistical->anomalies,
            'trends' => $statistical->trends,
            'baselines' => $statistical->baselines,
        ];

        $meta = [
            'report_type' => $ctx->reportType,
            'schema_version' => $ctx->engineVersion === 'next' ? 2 : 1,
            'engine_version' => $ctx->engineVersion,
            'generated_at' => $ctx->clock->toIso8601String(),
            'timezone' => $ctx->timezone,
            'dedupe_key' => sprintf('atlas-ai-performance:%s:%s', $ctx->reportType, $ctx->windowStart->toDateString()),
            'confidence' => $trust->trustScore,
            'run_id' => $runId,
        ];

        return new ReportPayload(frozen: $frozen, extended: $extended, meta: $meta);
    }

    private function decisionFor(TrustResult $trust, DiagnosticResult $diagnostic, RecommendationResult $rec): string
    {
        if ($trust->trustLevel === 'insufficient') {
            return 'Janela tem sample insuficiente para análise — claims suprimidos.';
        }
        if (! empty($diagnostic->findings)) {
            $count = count($diagnostic->findings);

            return sprintf('%d achado(s) diagnóstico(s); %d nova(s) recomendação(ões).', $count, count($rec->created));
        }

        return sprintf('Atlas operacional — trust %s, %d traces analisados.', $trust->trustLevel, $trust->sampleCount);
    }

    private function highlightsFor(WindowAggregates $aggregates, TrustResult $trust): array
    {
        $totals = (array) ($aggregates->scorecard['totals'] ?? []);
        $highlights = [];
        if (isset($totals['final_quality_avg'])) {
            $highlights[] = sprintf('quality %.1f', (float) $totals['final_quality_avg']);
        }
        if (isset($totals['final_efficiency_avg'])) {
            $highlights[] = sprintf('efficiency %.1f', (float) $totals['final_efficiency_avg']);
        }
        $highlights[] = sprintf('trust %s (%d traces)', $trust->trustLevel, $trust->sampleCount);

        return $highlights;
    }

    private function actionsFromRecommendations(RecommendationResult $rec): array
    {
        $actions = [];
        foreach ($rec->created as $r) {
            $dimDesc = collect((array) $r->target_dimension)
                ->map(fn ($v, $k) => "{$k}={$v}")
                ->implode(' × ');
            $actions[] = sprintf('[%s] %s — %s', strtoupper($r->kind), $r->target_metric, $dimDesc);
        }

        return $actions;
    }

    private function risksFromTrust(TrustResult $trust, WindowAggregates $aggregates): array
    {
        $risks = [];
        if ($trust->skipped) {
            $risks[] = ['kind' => 'trust_gate_skipped', 'severity' => 'warning', 'message' => $trust->skipReason ?? 'unknown'];
        }
        if ($aggregates->hasMixedAggregatorVersions) {
            $risks[] = ['kind' => 'mixed_aggregator_versions', 'severity' => 'critical', 'message' => 'Window contains mixed aggregator versions; trends suppressed.'];
        }
        if ($trust->trustLevel === 'insufficient') {
            $risks[] = ['kind' => 'trust_insufficient', 'severity' => 'warning', 'message' => sprintf('Trust score %.2f below threshold; downstream claims suppressed.', $trust->trustScore)];
        }

        return $risks;
    }

    private function fullTextFor(ReportContext $ctx, WindowAggregates $aggregates, TrustResult $trust, DiagnosticResult $diagnostic, RecommendationResult $rec): string
    {
        $lines = [
            sprintf('Atlas Performance Report — %s', $ctx->windowStart->toDateString()),
            sprintf('Trust: %s (%.2f) | Sample: %d traces | Aggregator: %s', $trust->trustLevel, $trust->trustScore, $trust->sampleCount, $aggregates->aggregatorVersion),
            '',
            sprintf('Findings: %d | New recommendations: %d | Reaffirmed: %d', count($diagnostic->findings), count($rec->created), count($rec->reaffirmed)),
        ];
        foreach ($diagnostic->findings as $f) {
            $lines[] = sprintf('  - %s [%s] %.1f%% explained by %s', $f->metric, $f->confidenceBand, ($f->explainedFraction ?? 0) * 100, json_encode($f->attributionDimensions));
        }

        return implode("\n", $lines);
    }
}
