<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopImpactReceiptService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Throwable;

/**
 * L2-7 — o medidor do exponencial: compara o estado de HOJE com o Marco Zero congelado
 * no Evidence Ledger (12/06). Cada métrica é resolvida de fonte viva (scorecard, tabelas
 * do Loop, runtime semântico) — nunca declarada. Sem número, "está melhorando" é
 * narrativa; com este comando, é delta auditável.
 */
class AtlasFableDeltaCommand extends Command
{
    protected $signature = 'atlas:fable:delta
        {--baseline= : Caminho do JSON do Marco Zero (default: o congelado de 12/06)}
        {--json : Saída JSON canônica}';

    protected $description = 'Compara o estado atual com o Marco Zero da campanha Fable (deltas resolvidos por evidência viva).';

    public function handle(): int
    {
        $baselinePath = trim((string) $this->option('baseline'))
            ?: storage_path('app/atlas/evidence/marco-zero-fable-2026-06-11.json');
        if (! is_file($baselinePath)) {
            $this->components->error('Marco Zero não encontrado: '.$baselinePath);

            return self::FAILURE;
        }
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        if (! is_array($baseline)) {
            $this->components->error('Marco Zero ilegível.');

            return self::FAILURE;
        }

        $now = $this->currentState();
        $b = $baseline['baseline'] ?? [];

        $report = [
            'schema_version' => 'atlas.fable.delta.v1',
            'baseline_recorded_at' => $baseline['recorded_at'] ?? null,
            'metrics' => [
                'scorecard_overall' => $this->metric(
                    (float) data_get($b, 'maturity_scorecard.acos_overall', 7.86),
                    $now['scorecard_overall'],
                ),
                'loop_proposals_merged_to_main' => $this->metric(
                    0.0, // Marco Zero: 72 presas em quarentena, 0 merged — o composto não existia
                    $now['merged_to_main'],
                ),
                'loop_impact_receipt_coverage_pct' => $this->metric(
                    0.0,
                    (float) ($now['impact_receipts']['coverage_pct'] ?? 0.0),
                ),
                'loop_impact_receipt_avg_score' => $this->metric(
                    0.0,
                    (float) ($now['impact_receipts']['avg_impact_score'] ?? 0.0),
                ),
                'loop_impact_receipts' => [
                    'baseline' => [],
                    'current' => $now['impact_receipts'],
                    'note' => 'L4-3: categoria, tamanho e alvo real-vs-generated por merge',
                ],
                'capture_gate_mode' => [
                    'baseline' => (string) data_get($b, 'learning_capture_quality_7d.gate_mode', 'observe'),
                    'current' => (string) config('atlas.ai.capture_quality_gate.mode'),
                    'note' => 'baseline mediu 94% waste em observe; enforce dropa ruído na fonte',
                ],
                'semantic_recall_real' => [
                    'baseline' => false, // registrado como placeholder no Marco Zero
                    'current' => $now['semantic_available'],
                ],
                'cost_measured_coverage_pct' => $this->metric(
                    0.0, // Marco Zero: 94/94 execuções com custo "unknown" — eixo de custo cego
                    $now['cost_measured_coverage_pct'],
                ),
            ],
            'sources' => [
                'scorecard' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)',
                'merged' => 'atlas_loop_proposals.merged_to_main=true (escopo governado)',
                'impact_receipts' => 'atlas_loop_proposals.quality._impact_receipt (categoria/tamanho/alvo por merge)',
                'semantic' => 'SemanticRetrievalRuntime::available()',
                'cost' => 'ai_programming_runtime_telemetry_events.cost_estimate_usd>0 / total (% medido por sinal real)',
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Marco Zero', (string) $report['baseline_recorded_at']);
        foreach ($report['metrics'] as $name => $m) {
            $line = isset($m['delta'])
                ? $m['baseline'].' → '.$m['current'].' (Δ '.($m['delta'] >= 0 ? '+' : '').$m['delta'].')'
                : json_encode(['de' => $m['baseline'], 'para' => $m['current']]);
            $this->components->twoColumnDetail($name, $line);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{baseline: float, current: float, delta: float}
     */
    private function metric(float $baseline, float $current): array
    {
        return ['baseline' => $baseline, 'current' => $current, 'delta' => round($current - $baseline, 3)];
    }

    /**
     * @return array{scorecard_overall: float, merged_to_main: float, impact_receipts: array<string,mixed>, semantic_available: bool, cost_measured_coverage_pct: float}
     */
    private function currentState(): array
    {
        $scorecard = 0.0;
        try {
            $scorecard = (float) data_get(app(AtlasCognitionScoreCardService::class)->build(), 'score.overall_out_of_10', 0.0);
        } catch (Throwable) {
        }

        $merged = 0.0;
        try {
            if (DatabaseTableAvailability::has('atlas_loop_proposals')) {
                $merged = (float) AtlasLoopProposal::query()->where('merged_to_main', true)->count();
            }
        } catch (Throwable) {
        }

        $semantic = false;
        try {
            $semantic = app(SemanticRetrievalRuntime::class)->available();
        } catch (Throwable) {
        }

        $impactReceipts = [];
        try {
            $impactReceipts = app(AtlasLoopImpactReceiptService::class)->aggregate();
        } catch (Throwable) {
        }

        // L3-10: cost-measured coverage — % of recent execution events that
        // carry a MEASURED cost (cost_estimate_usd > 0), resolved from the live
        // telemetry ledger. Marco Zero = 0% (94/94 "unknown"); DoD = >80% of
        // new events. Read-only, scoped to a recent window so it tracks NEW work.
        $coverage = 0.0;
        try {
            if (DatabaseTableAvailability::has('ai_programming_runtime_telemetry_events')) {
                $recent = AiProgrammingRuntimeTelemetryEvent::query()
                    ->where('occurred_at', '>=', now()->subDays(14));
                $total = (clone $recent)->count();
                if ($total > 0) {
                    $measured = (clone $recent)->where('cost_estimate_usd', '>', 0)->count();
                    $coverage = round(($measured / $total) * 100, 1);
                }
            }
        } catch (Throwable) {
        }

        return [
            'scorecard_overall' => $scorecard,
            'merged_to_main' => $merged,
            'impact_receipts' => $impactReceipts,
            'semantic_available' => $semantic,
            'cost_measured_coverage_pct' => $coverage,
        ];
    }
}
