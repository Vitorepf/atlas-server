<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiTraceMetricSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class AiInboxHumanPresentation
{
    /**
     * @return array<string,mixed>
     */
    public function forItem(AiInboxItem $item): array
    {
        $payload = is_array($item->payload) ? $item->payload : [];

        if (is_array($payload['health'] ?? null)) {
            return $this->healthPresentation($item, (array) $payload['health']);
        }

        if (is_array($payload['report'] ?? null)) {
            return $this->performancePresentation($item, (array) $payload['report']);
        }

        return $this->fallbackPresentation($item);
    }

    /**
     * @param  array<string,mixed>  $health
     * @return array<string,mixed>
     */
    private function healthPresentation(AiInboxItem $item, array $health): array
    {
        $status = (string) ($health['status'] ?? $item->severity ?? 'unknown');
        $score = $health['health_score'] ?? null;
        $issues = collect($health['issues'] ?? [])->filter(fn (mixed $issue): bool => is_array($issue))->values();
        $actions = collect($health['actions'] ?? [])->filter()->map(fn (mixed $action): string => $this->humanAction((string) $action))->values();
        $criticalCount = $issues->where('severity', 'critical')->count();
        $warningCount = $issues->where('severity', 'warning')->count();
        $traces = data_get($health, 'sample.traces', data_get($health, 'sample.trace_count'));
        $window = (array) ($health['window'] ?? []);
        $sampleConfidence = (string) data_get($health, 'sample.confidence', 'normal');

        $metrics = [
            $this->metric('Saude', $this->scoreValue($score), $this->metricTone($status)),
            $this->metric('Sinais criticos', (string) $criticalCount, $criticalCount > 0 ? 'critical' : 'ok'),
            $this->metric('Alertas', (string) $warningCount, $warningCount > 0 ? 'warning' : 'ok'),
        ];

        if (is_numeric($traces)) {
            $metrics[] = $this->metric('Traces analisadas', (string) ((int) $traces), $sampleConfidence === 'limited' ? 'warning' : 'neutral');
        }

        if ($window !== []) {
            $metrics[] = $this->metric('Janela', $this->windowLabel($window), 'neutral');
        }

        $issueItems = $issues
            ->take(6)
            ->map(fn (array $issue): array => [
                'label' => $this->metricLabel((string) ($issue['key'] ?? 'metric')),
                'value' => $this->metricValue((string) ($issue['key'] ?? ''), $issue['value'] ?? null),
                'threshold' => $this->metricValue((string) ($issue['key'] ?? ''), $issue['threshold'] ?? null),
                'severity' => (string) ($issue['severity'] ?? 'warning'),
                'meaning' => $this->issueMeaning((string) ($issue['key'] ?? ''), (string) ($issue['summary'] ?? '')),
            ])
            ->values()
            ->all();

        return $this->base($item) + [
            'headline' => $this->healthHeadline($status, $score),
            'plain_summary' => $this->healthSummary($status, $score, $criticalCount, $warningCount, $sampleConfidence),
            'primary_metric' => $this->metric('Saude', $this->scoreValue($score), $this->metricTone($status)),
            'metrics' => $metrics,
            'why_this_matters' => 'Este insight indica risco operacional no Atlas AI. Ele serve para revisao humana antes de alterar prompts, providers, custos ou automacoes.',
            'operator_next_step' => $actions->first()
                ?? 'Abrir as evidencias, revisar os sinais fora do limite e decidir manualmente se deve corrigir, adiar ou manter em observacao.',
            'recommended_actions' => $actions->take(6)->all(),
            'sections' => [
                [
                    'title' => 'Sinais fora do limite',
                    'items' => $issueItems,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function performancePresentation(AiInboxItem $item, array $report): array
    {
        $summary = $this->performanceSummary($item, $report);
        $costOverlay = $this->currentCostOverlay($report);
        if ($costOverlay !== []) {
            $summary = array_replace($summary, $costOverlay);
        }
        $status = (string) ($report['status'] ?? data_get($report, 'health.status', $item->severity ?? 'unknown'));
        $actions = collect($report['actions'] ?? $report['next_actions'] ?? [])->filter()->map(fn (mixed $action): string => $this->sentence((string) $action))->values();
        $reportDate = (string) ($report['report_date'] ?? $report['date'] ?? '');
        $traces = $summary['traces'] ?? null;
        $quality = $summary['quality_avg'] ?? null;
        $efficiency = $summary['efficiency_avg'] ?? null;
        $cost = $summary['cost_usd_estimate'] ?? null;
        $unknownCostRate = $summary['unknown_cost_rate'] ?? null;
        $costVisibilityRecovered = $costOverlay !== []
            && is_numeric($unknownCostRate)
            && (float) $unknownCostRate <= 0.0;
        $visibleActions = $this->performanceActions($actions, $costVisibilityRecovered);

        $metrics = [
            $this->metric('Traces', is_numeric($traces) ? (string) ((int) $traces) : 'indisponivel', 'neutral'),
            $this->metric('Qualidade media', $this->numberValue($quality), $this->qualityTone($quality)),
            $this->metric('Eficiencia media', $this->numberValue($efficiency), $this->qualityTone($efficiency)),
            $this->metric('First-pass', $this->percentValue($summary['first_pass_success_rate'] ?? null), 'neutral'),
            $this->metric('P95 app visivel', $this->msValue($summary['app_visible_p95_ms'] ?? null), 'neutral'),
            $this->metric('Custo estimado', is_numeric($cost) ? '$'.number_format((float) $cost, 4, '.', '') : 'indisponivel', 'neutral'),
            $this->metric('Custo desconhecido', $this->percentValue($unknownCostRate), is_numeric($unknownCostRate) && (float) $unknownCostRate > 0 ? 'warning' : 'ok'),
        ];
        if ($costOverlay !== []) {
            $metrics[] = $this->metric('Custo recalculado', 'sim', 'ok');
        }

        $risks = collect($report['risks'] ?? [])
            ->filter(fn (mixed $risk): bool => is_array($risk))
            ->reject(fn (array $risk): bool => $costVisibilityRecovered
                && in_array((string) ($risk['key'] ?? ''), ['unknown_cost_rate', 'cost_visibility'], true))
            ->take(6)
            ->map(fn (array $risk): array => [
                'label' => $this->metricLabel((string) ($risk['key'] ?? 'risk')),
                'severity' => (string) ($risk['severity'] ?? 'warning'),
                'meaning' => $this->sentence((string) ($risk['summary'] ?? 'Risco detectado no relatorio.')),
            ])
            ->values()
            ->all();
        $sections = collect([
            [
                'title' => 'Riscos detectados',
                'items' => $risks,
            ],
            [
                'title' => 'Nota de custo',
                'items' => $costOverlay !== [] ? [[
                    'label' => 'Custo recalculado',
                    'severity' => 'info',
                    'meaning' => 'Este item e um snapshot historico, mas os campos de custo foram recalculados com as rates operacionais atuais da mesma janela.',
                ]] : [],
            ],
        ])
            ->filter(fn (array $section): bool => $section['items'] !== [])
            ->values()
            ->all();

        return $this->base($item) + [
            'headline' => str_contains(strtolower((string) $item->title), '3/7/15/30')
                ? 'Performance consolidada do Atlas AI'
                : 'Relatorio de performance do Atlas AI',
            'plain_summary' => trim('Status '.$this->statusWord($status).'; '.(is_numeric($traces) ? ((int) $traces).' traces; ' : '').'qualidade '.$this->numberValue($quality).'; eficiencia '.$this->numberValue($efficiency).($reportDate !== '' ? '; data '.$reportDate : '').'.'),
            'primary_metric' => $this->metric('Qualidade media', $this->numberValue($quality), $this->qualityTone($quality)),
            'metrics' => $metrics,
            'why_this_matters' => 'Este relatorio mostra se o Atlas AI esta entregando respostas boas, eficientes, rapidas e com custo rastreavel.',
            'operator_next_step' => $visibleActions[0] ?? null
                ?? 'Comparar os principais indicadores com a janela anterior e investigar primeiro os riscos de maior severidade.',
            'recommended_actions' => $visibleActions,
            'sections' => $sections,
        ];
    }

    /**
     * @param  Collection<int,string>  $actions
     * @return array<int,string>
     */
    private function performanceActions($actions, bool $costVisibilityRecovered): array
    {
        if ($costVisibilityRecovered) {
            $actions = $actions->reject(fn (string $action): bool => str_contains($action, 'rates de estimativa operacional')
                || str_contains($action, 'rollup de telemetria'));
        }

        return $actions->take(6)->values()->all();
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function currentCostOverlay(array $report): array
    {
        $reportDate = (string) ($report['report_date'] ?? $report['date'] ?? '');
        if ($reportDate === '' || ! Schema::hasTable('ai_trace_metric_summaries') || ! Schema::hasTable('ai_traces')) {
            return [];
        }

        try {
            $timezone = (string) ($report['timezone'] ?? config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC')));
            $start = CarbonImmutable::parse($reportDate, $timezone)->startOfDay()->utc();
            $end = $start->addDay();
        } catch (\Throwable) {
            return [];
        }

        $base = AiTraceMetricSummary::query()
            ->whereHas('trace', fn ($query) => $query
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end));
        $count = (clone $base)->count();
        if ($count === 0) {
            return [];
        }

        $unknownCount = (clone $base)->where('cost_confidence', 'unknown')->count();
        $costMicrousd = (int) ((clone $base)->sum('cost_microusd') ?? 0);

        return [
            'cost_usd_estimate' => $costMicrousd / 1_000_000,
            'unknown_cost_rate' => $unknownCount / $count,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function performanceSummary(AiInboxItem $item, array $report): array
    {
        $summary = is_array($report['summary'] ?? null) ? (array) $report['summary'] : [];
        if ($summary !== []) {
            return $summary;
        }

        $metricRefs = $item->relationLoaded('contextBundle')
            ? collect($item->contextBundle?->metric_refs ?? [])
            : collect();
        $fromContext = $metricRefs
            ->filter(fn (mixed $metric): bool => is_array($metric) && is_string($metric['name'] ?? null))
            ->mapWithKeys(fn (array $metric): array => [(string) $metric['name'] => $metric['value'] ?? null])
            ->all();

        if ($fromContext !== []) {
            return $fromContext;
        }

        $fromHighlights = collect($report['highlights'] ?? [])
            ->filter(fn (mixed $highlight): bool => is_string($highlight))
            ->reduce(function (array $carry, string $highlight): array {
                $normalized = mb_strtolower($highlight);
                $value = $this->numberFromText($highlight);

                if (str_contains($normalized, 'traces')) {
                    $carry['traces'] = is_numeric($value) ? (int) $value : null;
                } elseif (str_contains($normalized, 'qualidade')) {
                    $carry['quality_avg'] = $value;
                } elseif (str_contains($normalized, 'eficiencia')) {
                    $carry['efficiency_avg'] = $value;
                }

                return $carry;
            }, []);

        $text = (string) ($report['full_text'] ?? $item->body ?? $item->summary ?? '');

        return array_filter([
            ...$fromHighlights,
            'traces' => $fromHighlights['traces'] ?? $this->numberAfterLabel($text, 'Traces'),
            'quality_avg' => $fromHighlights['quality_avg'] ?? $this->numberAfterLabel($text, 'Qualidade media'),
            'efficiency_avg' => $fromHighlights['efficiency_avg'] ?? $this->numberAfterLabel($text, 'Eficiencia media'),
            'first_pass_success_rate' => $this->percentAfterLabel($text, 'First-pass'),
            'needed_remediation_rate' => $this->percentAfterLabel($text, 'remedicao'),
            'app_visible_p95_ms' => $this->msAfterLabel($text, 'P95 app visivel'),
            'provider_latency_p95_ms' => $this->msAfterLabel($text, 'P95 provider'),
            'unknown_cost_rate' => $this->percentAfterLabel($text, 'custo desconhecido'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackPresentation(AiInboxItem $item): array
    {
        return $this->base($item) + [
            'headline' => $this->sentence((string) ($item->title ?? 'Item do Inbox')),
            'plain_summary' => $this->sentence((string) ($item->summary ?? $item->body ?? 'Sem resumo disponivel.')),
            'primary_metric' => $this->metric('Severidade', $this->severityLabel((string) ($item->severity ?? 'info')), $this->metricTone((string) ($item->severity ?? 'info'))),
            'metrics' => [
                $this->metric('Severidade', $this->severityLabel((string) ($item->severity ?? 'info')), $this->metricTone((string) ($item->severity ?? 'info'))),
                $this->metric('Status', $this->statusLabel((string) ($item->status ?? 'unknown')), 'neutral'),
                $this->metric('Categoria', (string) ($item->category ?? 'geral'), 'neutral'),
            ],
            'why_this_matters' => 'Este item foi registrado para revisao humana no Inbox.',
            'operator_next_step' => 'Ler o resumo, abrir o contexto autenticado se necessario e decidir manualmente o proximo passo.',
            'recommended_actions' => [],
            'sections' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function base(AiInboxItem $item): array
    {
        return [
            'schema_version' => 'atlas.inbox_item.human_presentation.v1',
            'severity_label' => $this->severityLabel((string) ($item->severity ?? 'info')),
            'status_label' => $this->statusLabel((string) ($item->status ?? 'unknown')),
            'review_required' => in_array((string) ($item->severity ?? ''), ['critical', 'warning'], true)
                && ! in_array((string) ($item->status ?? ''), ['resolved', 'dismissed', 'expired'], true),
            'auto_resolution_allowed' => false,
            'category_label' => $this->categoryLabel((string) ($item->category ?? 'geral')),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function metric(string $label, string $value, string $tone): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
        ];
    }

    private function healthHeadline(string $status, mixed $score): string
    {
        $prefix = $status === 'critical' ? 'Atlas AI em estado critico' : 'Atlas AI precisa de atencao';

        return $prefix.' - saude '.$this->scoreValue($score);
    }

    private function healthSummary(string $status, mixed $score, int $criticalCount, int $warningCount, string $sampleConfidence): string
    {
        $parts = [
            'Status '.$this->statusWord($status),
            'score '.$this->scoreValue($score),
            $criticalCount === 1 ? '1 sinal critico' : $criticalCount.' sinais criticos',
            $warningCount === 1 ? '1 alerta' : $warningCount.' alertas',
        ];

        if ($sampleConfidence === 'limited') {
            $parts[] = 'amostra pequena';
        }

        return implode('; ', $parts).'.';
    }

    private function scoreValue(mixed $score): string
    {
        return is_numeric($score) ? ((int) $score).'/100' : 'indisponivel';
    }

    private function numberValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') : 'indisponivel';
    }

    private function percentValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value * 100, 2, ',', '.'), '0'), ',').'%' : 'indisponivel';
    }

    private function msValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'indisponivel';
        }

        $ms = (float) $value;

        return $ms >= 1000
            ? rtrim(rtrim(number_format($ms / 1000, 2, ',', '.'), '0'), ',').'s'
            : ((int) round($ms)).'ms';
    }

    private function metricValue(string $key, mixed $value): string
    {
        if (str_ends_with($key, '_rate') || in_array($key, ['first_pass_success_rate', 'needed_remediation_rate'], true)) {
            return $this->percentValue($value);
        }

        if (str_ends_with($key, '_ms')) {
            return $this->msValue($value);
        }

        return $this->numberValue($value);
    }

    private function numberAfterLabel(string $text, string $label): ?float
    {
        if (! preg_match('/'.preg_quote($label, '/').'\s*:\s*([0-9]+(?:[,.][0-9]+)?)/iu', $text, $matches)) {
            return null;
        }

        return $this->numberFromText($matches[1]);
    }

    private function percentAfterLabel(string $text, string $label): ?float
    {
        if (! preg_match('/'.preg_quote($label, '/').'\s*:\s*([0-9]+(?:[,.][0-9]+)?)%/iu', $text, $matches)) {
            return null;
        }

        $value = $this->numberFromText($matches[1]);

        return is_numeric($value) ? $value / 100 : null;
    }

    private function msAfterLabel(string $text, string $label): ?float
    {
        if (! preg_match('/'.preg_quote($label, '/').'\s*:\s*([0-9]+(?:[,.][0-9]+)?)ms/iu', $text, $matches)) {
            return null;
        }

        return $this->numberFromText($matches[1]);
    }

    private function numberFromText(string $value): ?float
    {
        $normalized = str_replace(['.', ','], ['', '.'], trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function metricLabel(string $key): string
    {
        return match ($key) {
            'final_quality_avg', 'quality_avg' => 'Qualidade media',
            'final_efficiency_avg', 'efficiency_avg' => 'Eficiencia media',
            'context_efficiency_avg' => 'Eficiencia de contexto',
            'first_pass_success_rate' => 'First-pass',
            'needed_remediation_rate' => 'Remediacao',
            'unknown_cost_rate' => 'Custo desconhecido',
            'low_quality_rate' => 'Traces de baixa qualidade',
            'slow_trace_rate' => 'Traces lentas',
            'app_visible_avg_ms', 'app_visible_p95_ms' => 'Latencia visivel no app',
            'provider_latency_p95_ms' => 'Latencia do provider',
            'tool_denial_rate' => 'Negacao de tools',
            'tool_failure_rate' => 'Falha de tools',
            'tool_critical_risk_count' => 'Tools de risco critico',
            'insufficient_sample' => 'Amostra insuficiente',
            default => str_replace('_', ' ', $key),
        };
    }

    private function issueMeaning(string $key, string $fallback): string
    {
        return match ($key) {
            'final_quality_avg', 'quality_avg' => 'As respostas recentes ficaram abaixo do padrao esperado.',
            'final_efficiency_avg', 'efficiency_avg' => 'O Atlas esta gastando mais esforco do que deveria para entregar o resultado.',
            'first_pass_success_rate' => 'Poucas execucoes passaram de primeira sem correcao ou retrabalho.',
            'unknown_cost_rate' => 'O custo nao esta sendo calculado de forma confiavel para a janela.',
            'low_quality_rate' => 'Muitas execucoes recentes foram classificadas com score baixo.',
            'slow_trace_rate', 'app_visible_avg_ms', 'app_visible_p95_ms' => 'A experiencia pode parecer lenta para o usuario.',
            'insufficient_sample' => 'Ainda ha pouca amostra para conclusoes fortes.',
            default => $fallback !== '' ? $this->sentence($fallback) : 'Sinal fora do limite configurado.',
        };
    }

    private function windowLabel(array $window): string
    {
        $since = (string) ($window['since'] ?? $window['start'] ?? '');
        $until = (string) ($window['until'] ?? $window['end'] ?? '');

        if ($since !== '' && $until !== '') {
            return $since.' ate '.$until;
        }

        return $since !== '' ? 'desde '.$since : 'indisponivel';
    }

    private function qualityTone(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'neutral';
        }

        return (float) $value < 70 ? 'warning' : 'ok';
    }

    private function metricTone(string $status): string
    {
        return match ($status) {
            'critical', 'error' => 'critical',
            'warning', 'warn' => 'warning',
            'ok', 'healthy', 'info' => 'ok',
            default => 'neutral',
        };
    }

    private function statusWord(string $status): string
    {
        return match ($status) {
            'critical' => 'critico',
            'warning' => 'em atencao',
            'ok', 'healthy' => 'saudavel',
            default => $status,
        };
    }

    private function humanAction(string $action): string
    {
        if (str_starts_with($action, 'Open recent low-score traces')) {
            return 'Abrir traces recentes com score baixo e revisar prompt, contexto e provider antes de mudar comportamento.';
        }

        if (str_starts_with($action, 'Configure operational estimate cost rates')) {
            return $this->sentence(str_replace(
                ['Configure operational estimate cost rates for ', ' and rerun telemetry rollup.'],
                ['Configurar taxas estimadas de custo para ', ' e rodar o rollup de telemetria novamente'],
                $action,
            ));
        }

        if (str_starts_with($action, 'Fix provider/model attribution')) {
            return 'Corrigir a atribuicao de provider/model nos traces com custo desconhecido antes de importar taxas.';
        }

        if (str_starts_with($action, 'Review quality flags')) {
            return 'Revisar flags de qualidade e feedback humano recente; ajustar contexto ou politica de resposta primeiro.';
        }

        if (str_starts_with($action, 'Check queue wait')) {
            return 'Separar tempo de fila, latencia do provider e eventos de visibilidade no app para descobrir onde o tempo esta sendo gasto.';
        }

        if (str_starts_with($action, 'Compare first-pass failures')) {
            return 'Comparar falhas de first-pass com traces bem-sucedidos por tipo de tarefa e provider.';
        }

        return $this->sentence($action);
    }

    private function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Critico',
            'warning' => 'Atencao',
            'success' => 'Sucesso',
            'info' => 'Informativo',
            default => ucfirst($severity),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'unread' => 'Nao lido',
            'read' => 'Lido',
            'actioned' => 'Acionado',
            'resolved' => 'Resolvido',
            'dismissed' => 'Descartado',
            'expired' => 'Expirado',
            'snoozed' => 'Adiado',
            default => ucfirst($status),
        };
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'atlas_ai_performance' => 'Performance do Atlas AI',
            'atlas_ai_telemetry_health' => 'Saude operacional do Atlas AI',
            'memory_quality' => 'Qualidade de memoria',
            default => str_replace('_', ' ', $category),
        };
    }

    private function sentence(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        return str_ends_with($value, '.') ? $value : $value.'.';
    }
}
