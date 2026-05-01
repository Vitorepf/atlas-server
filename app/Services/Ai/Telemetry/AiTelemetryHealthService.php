<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\InsightInboxEmitter;
use Carbon\CarbonInterface;

class AiTelemetryHealthService
{
    public function __construct(
        private readonly AiTelemetryScorecardService $scorecards,
        private readonly AiProviderCostRateService $costRates,
        private readonly InsightInboxEmitter $insights,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluate(
        CarbonInterface $since,
        ?CarbonInterface $until = null,
        string $basis = 'computed_at',
        bool $exclusiveUntil = false,
    ): array
    {
        $until ??= now();
        $scorecard = $this->scorecards->build($since, $until, $basis, $exclusiveUntil);
        if (! ($scorecard['available'] ?? false)) {
            return [
                'available' => false,
                'status' => 'unknown',
                'health_score' => null,
                'issues' => [],
                'actions' => ['Run migrations before relying on Atlas AI telemetry health.'],
                'scorecard' => $scorecard,
            ];
        }

        $totals = (array) ($scorecard['totals'] ?? []);
        $traces = (int) ($totals['traces'] ?? 0);
        $minTraces = max(1, (int) config('atlas.ai_metrics.health_min_traces', 3));
        $issues = [];

        if ($traces < $minTraces) {
            $issues[] = $this->issue(
                'insufficient_sample',
                'watch',
                $traces,
                $minTraces,
                "A janela tem {$traces} trace(s), abaixo do minimo {$minTraces}.",
            );
        }

        $issues = [
            ...$issues,
            ...$this->scoreIssues('final_quality_avg', 'quality', $totals['final_quality_avg'] ?? null),
            ...$this->scoreIssues('final_efficiency_avg', 'efficiency', $totals['final_efficiency_avg'] ?? null),
            ...$this->minimumRateIssues('first_pass_success_rate', 'first_pass', $totals['first_pass_success_rate'] ?? null),
            ...$this->maximumRateIssues('needed_remediation_rate', 'remediation', $totals['needed_remediation_rate'] ?? null),
            ...$this->maximumRateIssues('unknown_cost_rate', 'unknown_cost', $this->unknownCostRate($totals)),
            ...$this->latencyIssues($totals['app_visible_avg_ms'] ?? null),
            ...$this->riskRateIssues($scorecard['risks'] ?? [], $traces),
        ];

        $status = $this->status($issues);
        $healthScore = $this->healthScore($issues);
        $evidence = $this->evidence($since, $until, $issues);

        return [
            'available' => true,
            'status' => $status,
            'health_score' => $healthScore,
            'window' => $scorecard['window'] ?? ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'thresholds' => $this->thresholds(),
            'issues' => $issues,
            'evidence' => $evidence,
            'actions' => $this->actions($status, $issues, $evidence),
            'scorecard' => $scorecard,
        ];
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @return array{emitted:bool,item_id:?string,reason:string}
     */
    public function emitInsight(array $evaluation, bool $dryRun = false): array
    {
        $status = (string) ($evaluation['status'] ?? 'unknown');
        if (! in_array($status, ['warning', 'critical'], true)) {
            return ['emitted' => false, 'item_id' => null, 'reason' => 'healthy_or_watch'];
        }

        $payload = $this->insightPayload($evaluation);
        if ($dryRun) {
            return ['emitted' => false, 'item_id' => null, 'reason' => 'dry_run_would_emit'];
        }

        $item = $this->insights->emit($payload);

        return [
            'emitted' => $item instanceof AiInboxItem,
            'item_id' => $item?->id,
            'reason' => $item ? 'emitted_or_deduped' : 'inbox_unavailable',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function thresholds(): array
    {
        return [
            'health_min_traces' => max(1, (int) config('atlas.ai_metrics.health_min_traces', 3)),
            'quality_warning_below' => (float) config('atlas.ai_metrics.health_quality_warning_below', 70),
            'quality_critical_below' => (float) config('atlas.ai_metrics.health_quality_critical_below', 55),
            'efficiency_warning_below' => (float) config('atlas.ai_metrics.health_efficiency_warning_below', 70),
            'efficiency_critical_below' => (float) config('atlas.ai_metrics.health_efficiency_critical_below', 55),
            'first_pass_warning_below' => (float) config('atlas.ai_metrics.health_first_pass_warning_below', 0.65),
            'first_pass_critical_below' => (float) config('atlas.ai_metrics.health_first_pass_critical_below', 0.45),
            'remediation_warning_above' => (float) config('atlas.ai_metrics.health_remediation_warning_above', 0.25),
            'remediation_critical_above' => (float) config('atlas.ai_metrics.health_remediation_critical_above', 0.45),
            'unknown_cost_warning_above' => (float) config('atlas.ai_metrics.health_unknown_cost_warning_above', 0.5),
            'unknown_cost_critical_above' => (float) config('atlas.ai_metrics.health_unknown_cost_critical_above', 0.9),
            'app_visible_warning_above_ms' => (int) config('atlas.ai_metrics.health_app_visible_warning_above_ms', 30_000),
            'app_visible_critical_above_ms' => (int) config('atlas.ai_metrics.health_app_visible_critical_above_ms', 120_000),
            'slow_trace_warning_rate_above' => (float) config('atlas.ai_metrics.health_slow_trace_warning_rate_above', 0.1),
            'low_quality_warning_rate_above' => (float) config('atlas.ai_metrics.health_low_quality_warning_rate_above', 0.1),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scoreIssues(string $field, string $label, mixed $value): array
    {
        if (! is_numeric($value)) {
            return [];
        }

        $thresholds = $this->thresholds();
        $warning = (float) $thresholds[$label.'_warning_below'];
        $critical = (float) $thresholds[$label.'_critical_below'];
        $value = (float) $value;

        if ($value < $critical) {
            return [$this->issue($field, 'critical', $value, $critical, "{$label} abaixo do limite critico.")];
        }

        if ($value < $warning) {
            return [$this->issue($field, 'warning', $value, $warning, "{$label} abaixo do limite esperado.")];
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function minimumRateIssues(string $field, string $label, mixed $value): array
    {
        if (! is_numeric($value)) {
            return [];
        }

        $thresholds = $this->thresholds();
        $warning = (float) $thresholds[$label.'_warning_below'];
        $critical = (float) $thresholds[$label.'_critical_below'];
        $value = (float) $value;

        if ($value < $critical) {
            return [$this->issue($field, 'critical', $value, $critical, "{$label} abaixo do limite critico.")];
        }

        if ($value < $warning) {
            return [$this->issue($field, 'warning', $value, $warning, "{$label} abaixo do limite esperado.")];
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function maximumRateIssues(string $field, string $label, mixed $value): array
    {
        if (! is_numeric($value)) {
            return [];
        }

        $thresholds = $this->thresholds();
        $warning = (float) $thresholds[$label.'_warning_above'];
        $critical = (float) $thresholds[$label.'_critical_above'];
        $value = (float) $value;

        if ($value > $critical) {
            return [$this->issue($field, 'critical', $value, $critical, "{$label} acima do limite critico.")];
        }

        if ($value > $warning) {
            return [$this->issue($field, 'warning', $value, $warning, "{$label} acima do limite esperado.")];
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function latencyIssues(mixed $appVisibleMs): array
    {
        if (! is_numeric($appVisibleMs)) {
            return [];
        }

        $thresholds = $this->thresholds();
        $value = (int) $appVisibleMs;
        $critical = (int) $thresholds['app_visible_critical_above_ms'];
        $warning = (int) $thresholds['app_visible_warning_above_ms'];

        if ($value > $critical) {
            return [$this->issue('app_visible_avg_ms', 'critical', $value, $critical, 'Latencia percebida no app acima do limite critico.')];
        }

        if ($value > $warning) {
            return [$this->issue('app_visible_avg_ms', 'warning', $value, $warning, 'Latencia percebida no app acima do limite esperado.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $risks
     * @return array<int,array<string,mixed>>
     */
    private function riskRateIssues(array $risks, int $traces): array
    {
        if ($traces <= 0) {
            return [];
        }

        $thresholds = $this->thresholds();
        $issues = [];
        $slowRate = ((int) ($risks['slow_count'] ?? 0)) / $traces;
        $lowQualityRate = ((int) ($risks['low_quality_count'] ?? 0)) / $traces;

        if ($slowRate > (float) $thresholds['slow_trace_warning_rate_above']) {
            $issues[] = $this->issue('slow_trace_rate', 'warning', round($slowRate, 4), $thresholds['slow_trace_warning_rate_above'], 'Muitas traces lentas na janela.');
        }

        if ($lowQualityRate > (float) $thresholds['low_quality_warning_rate_above']) {
            $issues[] = $this->issue('low_quality_rate', 'warning', round($lowQualityRate, 4), $thresholds['low_quality_warning_rate_above'], 'Muitas traces com score baixo na janela.');
        }

        return $issues;
    }

    private function unknownCostRate(array $totals): ?float
    {
        $traces = (int) ($totals['traces'] ?? 0);
        if ($traces <= 0) {
            return null;
        }

        return round(((int) ($totals['unknown_cost_count'] ?? 0)) / $traces, 4);
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $key, string $severity, mixed $value, mixed $threshold, string $summary): array
    {
        return compact('key', 'severity', 'value', 'threshold', 'summary');
    }

    /**
     * @param  array<int,array<string,mixed>>  $issues
     */
    private function status(array $issues): string
    {
        if (collect($issues)->contains('severity', 'critical')) {
            return 'critical';
        }

        if (collect($issues)->contains('severity', 'warning')) {
            return 'warning';
        }

        if (collect($issues)->contains('severity', 'watch')) {
            return 'watch';
        }

        return 'healthy';
    }

    /**
     * @param  array<int,array<string,mixed>>  $issues
     */
    private function healthScore(array $issues): int
    {
        $penalty = collect($issues)->sum(fn (array $issue): int => match ($issue['severity'] ?? null) {
            'critical' => 28,
            'warning' => 14,
            'watch' => 5,
            default => 0,
        });

        return max(0, 100 - (int) $penalty);
    }

    /**
     * @param  array<int,array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function evidence(CarbonInterface $since, CarbonInterface $until, array $issues): array
    {
        $keys = collect($issues)->pluck('key')->all();
        $evidence = [];

        if (in_array('unknown_cost_rate', $keys, true)) {
            $evidence['missing_cost_rates'] = $this->costRates->missingRates($since, $until, 10);
        }

        return $evidence;
    }

    /**
     * @param  array<int,array<string,mixed>>  $issues
     * @param  array<string,mixed>  $evidence
     * @return array<int,string>
     */
    private function actions(string $status, array $issues, array $evidence = []): array
    {
        if ($status === 'healthy') {
            return ['Keep hourly rollup enabled and review provider/cost rates when models change.'];
        }

        if ($status === 'watch') {
            return ['Collect more traces before changing prompts/providers.', 'Keep monitoring the same window after the next few runs.'];
        }

        $keys = collect($issues)->pluck('key')->all();
        $actions = ['Open recent low-score traces and inspect prompt/context/provider evidence before changing behavior.'];

        if (in_array('unknown_cost_rate', $keys, true)) {
            $missingRates = collect($evidence['missing_cost_rates'] ?? []);
            $missing = $missingRates
                ->filter(fn (array $row): bool => (bool) ($row['can_import_rate'] ?? false))
                ->map(fn (array $row): string => "{$row['provider']}/{$row['model']}")
                ->take(3)
                ->implode(', ');

            if ($missing !== '') {
                $actions[] = "Configure operational estimate cost rates for {$missing} and rerun telemetry rollup.";
            }

            if ($missingRates->contains(fn (array $row): bool => ! (bool) ($row['can_import_rate'] ?? false))) {
                $actions[] = 'Fix provider/model attribution for traces with unknown cost before importing rates.';
            }

            if ($missing === '' && ! $missingRates->contains(fn (array $row): bool => ! (bool) ($row['can_import_rate'] ?? false))) {
                $actions[] = 'Configure operational estimate cost rates and rerun telemetry rollup.';
            }
        }
        if (in_array('final_quality_avg', $keys, true) || in_array('low_quality_rate', $keys, true)) {
            $actions[] = 'Review quality flags and recent human feedback; adjust context selection or response policy first.';
        }
        if (in_array('final_efficiency_avg', $keys, true) || in_array('slow_trace_rate', $keys, true) || in_array('app_visible_avg_ms', $keys, true)) {
            $actions[] = 'Check queue wait, provider latency and mobile visibility events to isolate where time is spent.';
        }
        if (in_array('needed_remediation_rate', $keys, true) || in_array('first_pass_success_rate', $keys, true)) {
            $actions[] = 'Compare first-pass failures against successful traces by task type and provider.';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @return array<string,mixed>
     */
    private function insightPayload(array $evaluation): array
    {
        $status = (string) ($evaluation['status'] ?? 'unknown');
        $window = (array) ($evaluation['window'] ?? []);
        $issues = collect($evaluation['issues'] ?? [])->take(6)->values()->all();
        $healthScore = $evaluation['health_score'] ?? null;

        return [
            'title' => 'Atlas AI precisa de revisao operacional',
            'summary' => "Telemetry health {$status}; score {$healthScore}/100.",
            'body' => implode("\n\n", [
                'O scorecard de telemetria indicou risco operacional no Atlas AI.',
                'Issues: '.json_encode($issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'Acoes sugeridas: '.implode(' | ', $evaluation['actions'] ?? []),
            ]),
            'body_for_thread' => 'Use esta conversa para revisar scorecard, traces de baixa pontuacao, custo, latencia, continuidade e provider antes de alterar prompts ou automacoes.',
            'category' => 'atlas',
            'insight_kind' => 'atlas_ai_telemetry_health',
            'severity' => $status === 'critical' ? 'critical' : 'warning',
            'dedupe_key' => 'insight:atlas-ai-telemetry-health:'.now()->toDateString().':'.$status,
            'metric_refs' => collect($evaluation['scorecard']['totals'] ?? [])
                ->map(fn (mixed $value, string $key): array => ['name' => $key, 'value' => $value])
                ->values()
                ->all(),
            'raw_payload' => [
                'health' => $evaluation,
                'window' => $window,
            ],
            'confidence' => $status === 'critical' ? 0.9 : 0.78,
        ];
    }
}
