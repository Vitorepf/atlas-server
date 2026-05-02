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
                'actions' => ['Run migrations before relying on Atlas telemetry health.'],
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
            // Tool issues — Fix 7c F4. Only fire when min_calls threshold is met
            // (avoids "1 denial in 5 calls = 20% rate" false positives at low volume).
            ...$this->toolIssues($scorecard['tools'] ?? []),
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
            // Tool thresholds — Fix 7c F4
            'tool_denial_warning_above' => (float) config('atlas.ai_metrics.tool_denial_warning_above', 0.15),
            'tool_denial_critical_above' => (float) config('atlas.ai_metrics.tool_denial_critical_above', 0.40),
            'tool_denial_min_calls' => (int) config('atlas.ai_metrics.tool_denial_min_calls', 10),
            'tool_failure_warning_above' => (float) config('atlas.ai_metrics.tool_failure_warning_above', 0.20),
            'tool_failure_critical_above' => (float) config('atlas.ai_metrics.tool_failure_critical_above', 0.50),
            'tool_failure_min_calls' => (int) config('atlas.ai_metrics.tool_failure_min_calls', 10),
        ];
    }

    /**
     * Tool risk issues — Fix 7c F4. Three independent signals:
     *  1. tool_denial_rate above warning/critical (with min_calls floor)
     *  2. tool_failure_rate above warning/critical (with min_calls floor)
     *  3. critical_risk_tool_count > 0 (always critical — single critical risk
     *     operation deserves attention regardless of total count)
     *
     * @param  array<string,mixed>  $tools
     * @return array<int,array<string,mixed>>
     */
    private function toolIssues(array $tools): array
    {
        if (! ($tools['available'] ?? false)) {
            return [];
        }

        $thresholds = $this->thresholds();
        $issues = [];

        $totalCalls = (int) ($tools['tool_calls_total'] ?? 0);

        // Denial rate (with min calls floor)
        if ($totalCalls >= $thresholds['tool_denial_min_calls']) {
            $denialRate = (float) ($tools['permission_denial_rate'] ?? 0);
            if ($denialRate > $thresholds['tool_denial_critical_above']) {
                $issues[] = $this->issue('tool_denial_rate', 'critical', $denialRate,
                    $thresholds['tool_denial_critical_above'],
                    'Taxa de negacao de tools acima do limite critico — provavel desalinhamento.');
            } elseif ($denialRate > $thresholds['tool_denial_warning_above']) {
                $issues[] = $this->issue('tool_denial_rate', 'warning', $denialRate,
                    $thresholds['tool_denial_warning_above'],
                    'Taxa de negacao de tools acima do limite — revisar prompts/permissoes.');
            }
        }

        // Failure rate (with min calls floor)
        if ($totalCalls >= $thresholds['tool_failure_min_calls']) {
            $failureRate = (float) ($tools['tool_failure_rate'] ?? 0);
            if ($failureRate > $thresholds['tool_failure_critical_above']) {
                $issues[] = $this->issue('tool_failure_rate', 'critical', $failureRate,
                    $thresholds['tool_failure_critical_above'],
                    'Taxa de falha de tools acima do limite critico — possivel regressao.');
            } elseif ($failureRate > $thresholds['tool_failure_warning_above']) {
                $issues[] = $this->issue('tool_failure_rate', 'warning', $failureRate,
                    $thresholds['tool_failure_warning_above'],
                    'Taxa de falha de tools acima do limite esperado.');
            }
        }

        // Critical risk operations — always critical, no rate threshold (single
        // critical-risk tool call is itself the alarm).
        $criticalCount = (int) ($tools['critical_risk_tool_count'] ?? 0);
        if ($criticalCount > 0) {
            $issues[] = $this->issue('tool_critical_risk_count', 'critical', $criticalCount, 0,
                "{$criticalCount} operacao(s) de tool com risk=critical na janela.");
        }

        return $issues;
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
        $actions = array_values(array_filter(
            (array) ($evaluation['actions'] ?? []),
            fn (mixed $action): bool => is_string($action),
        ));
        $sample = $this->sampleContext($evaluation);
        $breakdowns = $this->breakdowns((array) ($evaluation['scorecard'] ?? []));
        $notificationPolicy = $this->notificationPolicy($evaluation, $issues);
        $whyReceived = $this->whyReceived($evaluation, $issues, $notificationPolicy);

        return [
            'title' => 'Atlas precisa de revisao operacional',
            'summary' => $this->insightSummary($status, $healthScore, $issues, $sample),
            'body' => $this->insightBody($evaluation, $issues, $actions, $sample, $breakdowns, $whyReceived),
            'body_for_thread' => $this->insightThreadBody($evaluation, $issues, $actions, $sample, $notificationPolicy),
            'category' => 'atlas',
            'insight_kind' => 'atlas_ai_telemetry_health',
            'severity' => $status === 'critical' ? 'critical' : 'warning',
            'dedupe_key' => 'insight:atlas-ai-telemetry-health:'.now()->toDateString().':'.$status,
            'push_policy' => $notificationPolicy,
            'priority_score' => $status === 'critical' ? 75 : 62,
            'payload' => [
                'health' => [
                    'status' => $status,
                    'health_score' => is_numeric($healthScore) ? (int) $healthScore : null,
                    'window' => $window,
                    'why_received' => $whyReceived,
                    'notification_policy' => $notificationPolicy,
                    'sample' => $sample,
                    'breakdowns' => $breakdowns,
                    'issues' => $issues,
                    'actions' => $actions,
                    'evidence' => $evaluation['evidence'] ?? [],
                ],
            ],
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

    /**
     * @param  array<int,array<string,mixed>>  $issues
     * @param  array<string,mixed>  $sample
     */
    private function insightSummary(string $status, mixed $healthScore, array $issues, array $sample = []): string
    {
        $score = is_numeric($healthScore) ? (int) $healthScore : null;
        $criticalCount = collect($issues)->where('severity', 'critical')->count();
        $warningCount = collect($issues)->where('severity', 'warning')->count();
        $prefix = $status === 'critical' ? 'Saude critica' : 'Saude em atencao';
        $parts = [];

        if ($score !== null) {
            $parts[] = "score {$score}/100";
        }
        if ($criticalCount > 0) {
            $parts[] = $criticalCount === 1 ? '1 sinal critico' : "{$criticalCount} sinais criticos";
        }
        if ($warningCount > 0) {
            $parts[] = $warningCount === 1 ? '1 alerta' : "{$warningCount} alertas";
        }
        if (($sample['confidence'] ?? null) === 'limited') {
            $parts[] = 'amostra pequena';
        }

        return $prefix.(count($parts) > 0 ? ': '.implode(', ', $parts).'.' : '.');
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @param  array<int,array<string,mixed>>  $issues
     * @param  array<int,string>  $actions
     * @param  array<string,mixed>  $sample
     * @param  array<string,array<int,array<string,mixed>>>  $breakdowns
     * @param  array<string,mixed>  $whyReceived
     */
    private function insightBody(array $evaluation, array $issues, array $actions, array $sample, array $breakdowns, array $whyReceived): string
    {
        $window = (array) ($evaluation['window'] ?? []);
        $totals = (array) data_get($evaluation, 'scorecard.totals', []);
        $score = $evaluation['health_score'] ?? null;
        $traces = $totals['traces'] ?? null;
        $lines = [
            (string) ($whyReceived['message'] ?? 'O Atlas rodou a verificacao automatica de saude da telemetria e encontrou risco operacional no Atlas.'),
            '',
            'Janela analisada: '.$this->windowLabel($window),
            'Score: '.(is_numeric($score) ? ((int) $score).'/100' : 'indisponivel'),
            'Amostra: '.(is_numeric($traces) ? ((int) $traces).' trace(s)' : 'indisponivel'),
            'Confianca da leitura: '.$this->sampleConfidenceLabel((string) ($sample['confidence'] ?? 'normal')),
        ];

        if (($sample['confidence'] ?? null) === 'limited') {
            $lines[] = 'Observacao: a amostra ainda e pequena; trate como sinal de investigacao antes de tirar conclusoes definitivas.';
        }

        $surfaceLines = $this->breakdownLines($breakdowns['by_surface'] ?? []);
        if ($surfaceLines !== []) {
            $lines[] = '';
            $lines[] = 'Onde apareceu:';
            foreach ($surfaceLines as $line) {
                $lines[] = '- '.$line;
            }
        }

        $providerLines = $this->breakdownLines($breakdowns['by_provider'] ?? []);
        if ($providerLines !== []) {
            $lines[] = '';
            $lines[] = 'Provider/modelo:';
            foreach ($providerLines as $line) {
                $lines[] = '- '.$line;
            }
        }

        $lines[] = '';
        $lines[] = 'Politica de notificacao: '.(string) ($whyReceived['notification_label'] ?? 'registrado no Inbox operacional.');
        $lines[] = '';
        $lines[] = 'Principais sinais:';

        foreach ($issues as $issue) {
            $lines[] = '- '.$this->issueLine($issue);
        }

        if ($actions !== []) {
            $lines[] = '';
            $lines[] = 'Proximos passos sugeridos:';
            foreach ($actions as $action) {
                $lines[] = '- '.$this->actionLine($action);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @param  array<int,array<string,mixed>>  $issues
     * @param  array<int,string>  $actions
     * @param  array<string,mixed>  $sample
     * @param  array<string,mixed>  $notificationPolicy
     */
    private function insightThreadBody(array $evaluation, array $issues, array $actions, array $sample, array $notificationPolicy): string
    {
        return implode("\n\n", [
            'Use esta conversa para revisar o estado operacional do Atlas antes de alterar prompts, providers ou automacoes.',
            'Resumo: '.$this->insightSummary((string) ($evaluation['status'] ?? 'unknown'), $evaluation['health_score'] ?? null, $issues, $sample),
            'Confianca da amostra: '.$this->sampleConfidenceLabel((string) ($sample['confidence'] ?? 'normal')),
            'Politica de notificacao: '.(string) ($notificationPolicy['reason'] ?? 'atlas_insight'),
            'Sinais: '.implode('; ', array_map(fn (array $issue): string => $this->issueLine($issue), $issues)),
            'Acoes sugeridas: '.($actions === [] ? 'nenhuma acao registrada.' : implode('; ', array_map(fn (string $action): string => $this->actionLine($action), $actions))),
        ]);
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        $label = $this->metricLabel((string) ($issue['key'] ?? 'metric'));
        $severity = (string) ($issue['severity'] ?? 'warning');
        $value = $this->metricValue((string) ($issue['key'] ?? ''), $issue['value'] ?? null);
        $threshold = $this->metricValue((string) ($issue['key'] ?? ''), $issue['threshold'] ?? null);
        $summary = (string) ($issue['summary'] ?? '');

        return "{$label}: {$value} ({$severity}; limite {$threshold}). ".$this->issueMeaning((string) ($issue['key'] ?? ''), $summary);
    }

    private function metricLabel(string $key): string
    {
        return match ($key) {
            'final_quality_avg' => 'Qualidade media',
            'final_efficiency_avg' => 'Eficiencia media',
            'context_efficiency_avg' => 'Eficiencia de contexto',
            'first_pass_success_rate' => 'First-pass',
            'needed_remediation_rate' => 'Remediacao',
            'unknown_cost_rate' => 'Custo desconhecido',
            'low_quality_rate' => 'Traces de baixa qualidade',
            'slow_trace_rate' => 'Traces lentas',
            'app_visible_avg_ms' => 'Latencia visivel no app',
            'tool_denial_rate' => 'Negacao de tools',
            'tool_failure_rate' => 'Falha de tools',
            'tool_critical_risk_count' => 'Tools de risco critico',
            'insufficient_sample' => 'Amostra insuficiente',
            default => str_replace('_', ' ', $key),
        };
    }

    private function metricValue(string $key, mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'indisponivel';
        }

        $number = (float) $value;
        if (str_ends_with($key, '_rate') || in_array($key, ['first_pass_success_rate', 'needed_remediation_rate'], true)) {
            return rtrim(rtrim(number_format($number * 100, 2, ',', '.'), '0'), ',').'%';
        }

        if (str_ends_with($key, '_ms')) {
            return $number >= 1000
                ? rtrim(rtrim(number_format($number / 1000, 2, ',', '.'), '0'), ',').'s'
                : ((int) round($number)).'ms';
        }

        return rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',');
    }

    private function issueMeaning(string $key, string $fallback): string
    {
        return match ($key) {
            'final_quality_avg' => 'As respostas recentes ficaram abaixo do padrao esperado.',
            'final_efficiency_avg' => 'O Atlas esta gastando mais esforco do que deveria para entregar o resultado.',
            'first_pass_success_rate' => 'Poucas execucoes passaram de primeira sem correcao ou retrabalho.',
            'unknown_cost_rate' => 'O custo nao esta sendo calculado de forma confiavel para a janela.',
            'low_quality_rate' => 'Muitas execucoes recentes foram classificadas com score baixo.',
            'slow_trace_rate', 'app_visible_avg_ms' => 'A experiencia pode parecer lenta para o usuario.',
            default => $fallback !== '' ? $fallback : 'Sinal fora do limite configurado.',
        };
    }

    private function actionLine(string $action): string
    {
        if (str_starts_with($action, 'Open recent low-score traces')) {
            return 'Abrir traces recentes com score baixo e revisar prompt, contexto e provider antes de mudar comportamento.';
        }
        if (str_starts_with($action, 'Configure operational estimate cost rates')) {
            return str_replace(
                ['Configure operational estimate cost rates for ', ' and rerun telemetry rollup.'],
                ['Configurar taxas estimadas de custo para ', ' e rodar o rollup de telemetria novamente.'],
                $action,
            );
        }
        if (str_starts_with($action, 'Fix provider/model attribution')) {
            return 'Corrigir a atribuicao de provider/model nos traces com custo desconhecido antes de importar taxas.';
        }
        if (str_starts_with($action, 'Review quality flags')) {
            return 'Revisar flags de qualidade e feedback humano recente; ajustar selecao de contexto ou politica de resposta primeiro.';
        }
        if (str_starts_with($action, 'Check queue wait')) {
            return 'Separar tempo de fila, latencia do provider e eventos de visibilidade no app para descobrir onde o tempo esta sendo gasto.';
        }
        if (str_starts_with($action, 'Compare first-pass failures')) {
            return 'Comparar falhas de first-pass com traces bem-sucedidos por tipo de tarefa e provider.';
        }

        return $action;
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @return array<string,mixed>
     */
    private function sampleContext(array $evaluation): array
    {
        $traces = (int) data_get($evaluation, 'scorecard.totals.traces', 0);
        $minimum = max(1, (int) config('atlas.ai_metrics.health_min_traces', 3));
        $stable = max(20, $minimum);

        return [
            'traces' => $traces,
            'minimum_traces' => $minimum,
            'stable_traces' => $stable,
            'confidence' => $traces < $stable ? 'limited' : 'normal',
            'message' => $traces < $stable
                ? "A leitura usa {$traces} trace(s), abaixo da referencia de estabilidade {$stable}."
                : "A leitura usa {$traces} trace(s), suficiente para tendencia operacional.",
        ];
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function breakdowns(array $scorecard): array
    {
        return [
            'by_surface' => $this->breakdownRows((array) ($scorecard['by_surface'] ?? [])),
            'by_provider' => $this->breakdownRows((array) ($scorecard['by_provider'] ?? [])),
            'by_model' => $this->breakdownRows((array) ($scorecard['by_model'] ?? [])),
            'by_task_type' => $this->breakdownRows((array) ($scorecard['by_task_type'] ?? [])),
        ];
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function breakdownRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->sortByDesc(fn (array $row): int => (int) ($row['traces'] ?? 0))
            ->take(5)
            ->map(fn (array $row): array => [
                'bucket' => (string) ($row['bucket'] ?? 'unknown'),
                'traces' => (int) ($row['traces'] ?? 0),
                'quality_avg' => $this->nullableFloat($row['quality_avg'] ?? null),
                'efficiency_avg' => $this->nullableFloat($row['efficiency_avg'] ?? null),
                'first_pass_success_rate' => $this->nullableFloat($row['first_pass_success_rate'] ?? null),
                'unknown_cost_count' => (int) ($row['unknown_cost_count'] ?? 0),
                'latency_avg_ms' => $this->nullableFloat($row['latency_avg_ms'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function breakdownLines(array $rows): array
    {
        return collect($rows)
            ->take(3)
            ->map(function (array $row): string {
                $parts = [
                    ((string) ($row['bucket'] ?? 'unknown')).': '.((int) ($row['traces'] ?? 0)).' trace(s)',
                ];

                if (is_numeric($row['quality_avg'] ?? null)) {
                    $parts[] = 'qualidade '.$this->metricValue('final_quality_avg', $row['quality_avg']);
                }
                if (is_numeric($row['first_pass_success_rate'] ?? null)) {
                    $parts[] = 'first-pass '.$this->metricValue('first_pass_success_rate', $row['first_pass_success_rate']);
                }
                if (((int) ($row['unknown_cost_count'] ?? 0)) > 0) {
                    $parts[] = ((int) $row['unknown_cost_count']).' sem custo';
                }

                return implode(', ', $parts);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @param  array<int,array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function notificationPolicy(array $evaluation, array $issues): array
    {
        $interruptive = $this->hasInterruptiveIssue($issues);
        $status = (string) ($evaluation['status'] ?? 'unknown');

        if ($status === 'critical' && $interruptive) {
            return [
                'send' => 'immediate',
                'force' => true,
                'reason' => 'telemetry_health_interruptive',
                'channel' => 'push_immediate',
                'rationale' => 'Falha operacional com impacto direto; deve interromper.',
            ];
        }

        return [
            'send' => 'none',
            'reason' => 'telemetry_health_daily_digest',
            'channel' => 'inbox_and_morning_report',
            'rationale' => 'Diagnostico importante, mas nao interruptivo; fica no Inbox e no relatorio da manha.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $issues
     */
    private function hasInterruptiveIssue(array $issues): bool
    {
        $interruptiveKeys = [
            'app_visible_avg_ms',
            'tool_critical_risk_count',
            'tool_failure_rate',
            'tool_denial_rate',
        ];

        return collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? null) === 'critical'
            && in_array((string) ($issue['key'] ?? ''), $interruptiveKeys, true));
    }

    /**
     * @param  array<string,mixed>  $evaluation
     * @param  array<int,array<string,mixed>>  $issues
     * @param  array<string,mixed>  $notificationPolicy
     * @return array<string,mixed>
     */
    private function whyReceived(array $evaluation, array $issues, array $notificationPolicy): array
    {
        $status = (string) ($evaluation['status'] ?? 'unknown');
        $criticalCount = collect($issues)->where('severity', 'critical')->count();
        $warningCount = collect($issues)->where('severity', 'warning')->count();
        $message = $status === 'critical'
            ? 'O monitor automatico de telemetria abriu este item porque encontrou '.$this->countLabel($criticalCount, 'sinal critico', 'sinais criticos').' e '.$this->countLabel($warningCount, 'alerta', 'alertas').'.'
            : 'O monitor automatico de telemetria abriu este item porque encontrou '.$this->countLabel($warningCount, 'alerta', 'alertas').'.';
        $label = ($notificationPolicy['send'] ?? null) === 'immediate'
            ? 'push imediato, porque o sinal pode afetar operacao agora.'
            : 'sem push imediato; registrado no Inbox operacional e no relatorio da manha.';

        return [
            'message' => $message,
            'notification_label' => $label,
            'scheduler' => 'atlas:ai:telemetry:health --hours=48 --emit',
            'cadence' => 'hourly',
        ];
    }

    private function sampleConfidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'limited' => 'limitada por amostra pequena',
            default => 'normal',
        };
    }

    private function countLabel(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }

    /**
     * @param  array<string,mixed>  $window
     */
    private function windowLabel(array $window): string
    {
        $since = isset($window['since']) && is_string($window['since']) ? $window['since'] : null;
        $until = isset($window['until']) && is_string($window['until']) ? $window['until'] : null;

        if (! $since || ! $until) {
            return 'janela configurada';
        }

        return "{$since} ate {$until}";
    }
}
