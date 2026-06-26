<?php

declare(strict_types=1);

namespace App\Services\Ai\Telemetry;

use Carbon\CarbonImmutable;

/**
 * REPORT TEXT/BODY RENDERING concern, extracted from the god-class
 * {@see AiTelemetryPerformanceReportService}.
 *
 * Owns every text/body rendering method: dailySummaryText, multiSummaryText,
 * dailyBody, multiBody, threadBody, highlights, compactReportPayload plus
 * the formatMs helper (its sole callers are the moved body methods).
 *
 * Capabilities that STAY in the service (formatCostSummary, formatNumber,
 * formatSigned, formatPercent) are passed in as Closures — the SAME
 * closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator.
 */
class AiTelemetryReportBodyRenderer
{
    /**
     * @param  Closure(mixed): string  $formatCostSummary
     * @param  Closure(mixed): string  $formatNumber
     * @param  Closure(mixed): string  $formatSigned
     * @param  Closure(mixed): string  $formatPercent
     */
    public function __construct(
        private readonly Closure $formatCostSummary,
        private readonly Closure $formatNumber,
        private readonly Closure $formatSigned,
        private readonly Closure $formatPercent,
    ) {}

    public function dailySummaryText(array $current, array $comparison): string
    {
        $summary = (array) ($current['summary'] ?? []);
        $status = (string) data_get($current, 'health.status', 'unknown');
        $quality = ($this->formatNumber)($summary['quality_avg'] ?? null);
        $efficiency = ($this->formatNumber)($summary['efficiency_avg'] ?? null);
        $traces = (int) ($summary['traces'] ?? 0);
        $qualityDelta = ($this->formatSigned)($comparison['quality_avg']['delta_abs'] ?? null);

        return "Status {$status}; {$traces} traces; qualidade {$quality} ({$qualityDelta}); eficiencia {$efficiency}; custo ".($this->formatCostSummary)($summary).'.';
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    public function multiSummaryText(array $reports): string
    {
        $parts = collect($reports)
            ->map(function (array $report): string {
                $summary = (array) ($report['summary'] ?? []);

                return "{$report['days']}d: {$report['status']}, {$summary['traces']} traces, qualidade ".($this->formatNumber)($summary['quality_avg'] ?? null).', eficiencia '.($this->formatNumber)($summary['efficiency_avg'] ?? null);
            })
            ->implode(' | ');

        return 'Estrutura de desempenho Atlas: '.$parts.'.';
    }

    public function dailyBody(CarbonImmutable $date, string $timezone, array $current, array $previous, array $comparison, array $actions): string
    {
        $summary = (array) ($current['summary'] ?? []);
        $risks = collect($current['risks'] ?? [])->take(6)->map(fn (array $risk): string => "- {$risk['severity']} {$risk['key']}: {$risk['summary']}")->implode("\n");
        $actionsText = collect($actions)->map(fn (string $action): string => '- '.$action)->implode("\n");

        return implode("\n\n", array_filter([
            'Relatorio diario de performance do Atlas - '.$date->format('d/m/Y').' ('.$timezone.').',
            'Resumo executivo: '.$this->dailySummaryText($current, $comparison),
            implode("\n", [
                'Metricas centrais:',
                '- Traces: '.(int) ($summary['traces'] ?? 0),
                '- Qualidade media: '.($this->formatNumber)($summary['quality_avg'] ?? null).' | delta dia anterior: '.($this->formatSigned)($comparison['quality_avg']['delta_abs'] ?? null),
                '- Eficiencia media: '.($this->formatNumber)($summary['efficiency_avg'] ?? null).' | delta dia anterior: '.($this->formatSigned)($comparison['efficiency_avg']['delta_abs'] ?? null),
                '- First-pass: '.($this->formatPercent)($summary['first_pass_success_rate'] ?? null).' | remedicao: '.($this->formatPercent)($summary['needed_remediation_rate'] ?? null),
                '- P95 app visivel: '.$this->formatMs($summary['app_visible_p95_ms'] ?? null).' | P95 provider: '.$this->formatMs($summary['provider_latency_p95_ms'] ?? null),
                '- Custo: '.($this->formatCostSummary)($summary).' | custo desconhecido: '.($this->formatPercent)($summary['unknown_cost_rate'] ?? null),
            ]),
            $risks !== '' ? "Falhas e riscos detectados:\n".$risks : 'Falhas e riscos detectados: nenhum risco forte nesta janela.',
            "Acoes recomendadas:\n".($actionsText !== '' ? $actionsText : '- Manter rollup horario e revisar novamente no proximo relatorio.'),
            'Cobertura de dados: '.json_encode($current['data_quality']['coverage_rates'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Janela anterior comparada: '.($previous['window']['start'] ?? '-').' ate '.($previous['window']['end'] ?? '-').'.',
        ]));
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     * @param  array<int,string>  $actions
     */
    public function multiBody(CarbonImmutable $date, string $timezone, array $reports, array $actions): string
    {
        $windows = collect($reports)
            ->map(function (array $report): string {
                $summary = (array) ($report['summary'] ?? []);
                $delta = (array) data_get($report, 'comparison_to_previous_same_window.deltas.quality_avg', []);

                return implode("\n", [
                    "{$report['label']} ({$report['status']}):",
                    '- Traces: '.(int) ($summary['traces'] ?? 0),
                    '- Qualidade: '.($this->formatNumber)($summary['quality_avg'] ?? null).' | delta janela anterior: '.($this->formatSigned)($delta['delta_abs'] ?? null),
                    '- Eficiencia: '.($this->formatNumber)($summary['efficiency_avg'] ?? null),
                    '- First-pass: '.($this->formatPercent)($summary['first_pass_success_rate'] ?? null).' | remedicao: '.($this->formatPercent)($summary['needed_remediation_rate'] ?? null),
                    '- P95 app: '.$this->formatMs($summary['app_visible_p95_ms'] ?? null).' | custo: '.($this->formatCostSummary)($summary),
                ]);
            })
            ->implode("\n\n");

        $actionsText = collect($actions)->map(fn (string $action): string => '- '.$action)->implode("\n");

        return implode("\n\n", [
            'Relatorio estrutural de performance do Atlas ate '.$date->format('d/m/Y').' ('.$timezone.').',
            'Resumo executivo: '.$this->multiSummaryText($reports),
            $windows,
            "Melhorias, pioras e proximas acoes:\n".($actionsText !== '' ? $actionsText : '- Nenhuma acao nova com confianca suficiente.'),
            'Este relatorio compara cada janela com a janela imediatamente anterior de mesmo tamanho e usa trace_created_at como base auditavel.',
        ]);
    }

    public function threadBody(array $report): string
    {
        return implode("\n\n", [
            'Use esta conversa para decidir como melhorar o Atlas com base no relatorio operacional.',
            'Resumo: '.(string) ($report['summary_text'] ?? $report['executive_summary'] ?? ''),
            'Relatorio completo:',
            (string) ($report['body'] ?? ''),
        ]);
    }

    /**
     * @return array<int,string>
     */
    public function highlights(array $report): array
    {
        if (($report['report_type'] ?? null) === 'atlas_ai_multi_window_performance') {
            return collect($report['windows'] ?? [])
                ->map(fn (array $window): string => "{$window['days']}d {$window['status']}: {$window['summary']['traces']} traces, Q ".($this->formatNumber)($window['summary']['quality_avg'] ?? null).', E '.($this->formatNumber)($window['summary']['efficiency_avg'] ?? null))
                ->values()
                ->all();
        }

        $summary = (array) ($report['summary'] ?? []);

        return [
            'Traces: '.(int) ($summary['traces'] ?? 0),
            'Qualidade: '.($this->formatNumber)($summary['quality_avg'] ?? null),
            'Eficiencia: '.($this->formatNumber)($summary['efficiency_avg'] ?? null),
            'Custo: '.($this->formatCostSummary)($summary),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function compactReportPayload(array $report): array
    {
        $enginePayload = is_array($report['engine'] ?? null) ? $report['engine'] : null;
        if ((int) ($report['schema_version'] ?? 1) >= 2 && $enginePayload !== null && is_string($enginePayload['decision'] ?? null)) {
            return array_merge($enginePayload, [
                'report_type' => $report['report_type'] ?? null,
                'report_date' => $report['report_date'] ?? null,
                'timezone' => $report['timezone'] ?? null,
                'status' => $report['status'] ?? null,
                'health_score' => $report['health_score'] ?? null,
                'tools' => $report['tools'] ?? data_get($report, 'windows.0.tools'),
                'validation' => array_merge((array) ($enginePayload['validation'] ?? []), [
                    'dedupe_key' => $report['dedupe_key'] ?? null,
                    'schema_version' => $report['schema_version'] ?? 2,
                ]),
            ]);
        }

        return [
            'report_type' => $report['report_type'] ?? null,
            'report_date' => $report['report_date'] ?? null,
            'timezone' => $report['timezone'] ?? null,
            'status' => $report['status'] ?? null,
            'health_score' => $report['health_score'] ?? null,
            'summary' => $report['summary'] ?? null,
            'decision' => $report['executive_summary'] ?? $report['summary_text'] ?? null,
            'highlights' => $this->highlights($report),
            'next_actions' => $report['actions'] ?? [],
            'risks' => collect($report['risks'] ?? data_get($report, 'windows.0.risks', []))->take(6)->values()->all(),
            // Tools digest in compact mobile payload — Fix 7c F5. Mobile renderer
            // (mobile-inbox-item.tsx) already lists payload.report.* keys; adding
            // 'tools' here surfaces the digest without changing renderer code, since
            // unknown keys are gracefully ignored.
            'tools' => $report['tools'] ?? data_get($report, 'windows.0.tools'),
            'validation' => [
                'basis' => data_get($report, 'window.basis', 'trace_created_at'),
                'dedupe_key' => $report['dedupe_key'] ?? null,
                'schema_version' => $report['schema_version'] ?? 1,
            ],
            'full_text' => $report['body'] ?? null,
        ];
    }

    public function formatMs(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 0, ',', '.').'ms' : 'sem dado';
    }
}