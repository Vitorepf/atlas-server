<?php

namespace App\Services\Ai\Rivals2\Core;

use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;

/**
 * Report por task_type × arm: success_rate, custo, tempo, estabilidade, n.
 * NUNCA produz "best overall" nem score único colapsado. claim_allowed e
 * blockers vêm da adjudicação — report sem adjudicação nunca permite claim.
 */
class ReportBuilder
{
    public function build(string $runId): array
    {
        $receipts = RunReceipt::loadAll($runId);
        $adjPath = RunPaths::adjudicationPath($runId);
        $adjudication = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];
        $claimAllowed = ($adjudication['claim_allowed'] ?? false) === true;
        $blockers = $adjudication['claim_blockers'] ?? ['adjudication_missing'];

        $groups = [];
        foreach ($receipts as $receipt) {
            $groups["{$receipt->data['task_type']}|{$receipt->data['arm_id']}"][] = $receipt->data;
        }

        $rows = [];
        foreach ($groups as $key => $items) {
            [$taskType, $armId] = explode('|', $key, 2);
            $successFlags = array_map(fn ($r) => $r['status'] === 'success' ? 1.0 : 0.0, $items);
            $n = count($items);
            $successRate = array_sum($successFlags) / $n;
            // estabilidade = 1 - desvio-padrão do sucesso entre execuções (1.0 = sempre igual)
            $variance = array_sum(array_map(fn ($f) => ($f - $successRate) ** 2, $successFlags)) / $n;
            $rows[] = [
                'task_type' => $taskType,
                'arm_id' => $armId,
                'n' => $n,
                'success_rate' => round($successRate, 4),
                'avg_cost_usd' => round(array_sum(array_column($items, 'cost_usd')) / $n, 6),
                'avg_wall_ms' => (int) round(array_sum(array_column($items, 'wall_ms')) / $n),
                'stability' => round(1.0 - sqrt($variance), 4),
                'avg_patch_bloat' => ($bloats = array_filter(array_column($items, 'patch_bloat_ratio'), 'is_numeric')) === []
                    ? null
                    : round(array_sum($bloats) / count($bloats), 3),
                'reality' => $this->realityAggregate($items),
            ];
        }

        // Difficulty Calibrator: banda por linha + banda da suite (baseline bare mais forte).
        // Suite fácil demais = sinal de cola/contaminação/case trivial, não de modelo bom.
        $calibration = (new DifficultyCalibrator)->calibrate($rows);
        foreach ($rows as &$row) {
            $row['difficulty_band'] = $calibration['row_bands']["{$row['task_type']}|{$row['arm_id']}"] ?? null;
        }
        unset($row);

        $report = [
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => $rows,
            'difficulty_band' => $calibration['suite_band'],
            'difficulty_baseline' => $calibration['baseline'],
            'difficulty_flags' => $calibration['flags'],
            'claim_allowed' => $claimAllowed,
            'claim_blockers' => $blockers,
            'claim_scope' => $adjudication['claim_scope'] ?? null,
            'built_at' => now()->toIso8601String(),
        ];

        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::reportPath($runId),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(RunPaths::reportMarkdownPath($runId), $this->markdown($report));

        return $report;
    }

    /**
     * Agregado cross-run: varre runs/ e consolida por task_type × arm.
     * Só receipts de runs com adjudicação VÁLIDA contam para linhas com
     * claim; runs inválidos/não-adjudicados entram apenas na contagem de
     * excluded_runs (nunca somem silenciosamente).
     */
    public function buildAll(): array
    {
        $runsDir = RunPaths::runsDir();
        $runIds = is_dir($runsDir) ? array_values(array_diff(scandir($runsDir), ['.', '..'])) : [];

        $groups = [];
        $included = [];
        $excluded = [];
        foreach ($runIds as $runId) {
            $adjPath = RunPaths::adjudicationPath($runId);
            $adj = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];
            if (($adj['claim_allowed'] ?? false) !== true) {
                $excluded[] = ['run_id' => $runId, 'reason' => $adj === [] ? 'not_adjudicated' : 'invalid'];
                continue;
            }
            $included[] = $runId;
            foreach (RunReceipt::loadAll($runId) as $receipt) {
                $groups["{$receipt->data['task_type']}|{$receipt->data['arm_id']}"][] = $receipt->data;
            }
        }

        $rows = [];
        foreach ($groups as $key => $items) {
            [$taskType, $armId] = explode('|', $key, 2);
            $successFlags = array_map(fn ($r) => $r['status'] === 'success' ? 1.0 : 0.0, $items);
            $n = count($items);
            $successRate = array_sum($successFlags) / $n;
            $variance = array_sum(array_map(fn ($f) => ($f - $successRate) ** 2, $successFlags)) / $n;
            $rows[] = [
                'task_type' => $taskType,
                'arm_id' => $armId,
                'n' => $n,
                'success_rate' => round($successRate, 4),
                'avg_cost_usd' => round(array_sum(array_column($items, 'cost_usd')) / $n, 6),
                'avg_wall_ms' => (int) round(array_sum(array_column($items, 'wall_ms')) / $n),
                'stability' => round(1.0 - sqrt($variance), 4),
            ];
        }

        return [
            'schema_version' => 'atlas.rivals2.report_all.v1',
            'rows' => $rows,
            'included_runs' => $included,
            'excluded_runs' => $excluded,
            // agregado é leitura consolidada; claim continua POR RUN (escopo pinado lá)
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Agregado do Reality Score por task_type × arm — vetor de dimensões,
     * nunca colapsado. Só agrega o que os receipts realmente carregam.
     */
    private function realityAggregate(array $items): ?array
    {
        $cards = array_values(array_filter(array_column($items, 'reality')));
        if ($cards === []) {
            return null;
        }
        $n = count($cards);
        $rate = fn (string $key) => round(count(array_filter($cards, fn ($c) => ($c[$key] ?? null) === true)) / $n, 4);

        return [
            'n' => $n,
            'hidden_regression_pass_rate' => $rate('hidden_regression_pass'),
            'minimal_rate' => $rate('minimal'),
            'hardcode_suspects' => count(array_filter($cards, fn ($c) => ($c['hardcode_suspect'] ?? null) === true)),
            'avg_blast_radius_outside_golden' => round(array_sum(array_column($cards, 'blast_radius_outside_golden')) / $n, 2),
            'requires_judge' => $cards[0]['requires_judge'] ?? [],
        ];
    }

    private function markdown(array $report): string
    {
        $md = "# Rivals 2.0 — run {$report['run_id']}\n\n";
        $md .= 'difficulty_band: '.($report['difficulty_band'] ?? 'uncalibrated')."\n";
        $md .= 'claim_allowed: '.($report['claim_allowed'] ? 'true' : 'false')."\n";
        if ($report['claim_blockers'] !== []) {
            $md .= "claim_blockers:\n".implode("\n", array_map(fn ($b) => "- {$b}", $report['claim_blockers']))."\n";
        }
        $md .= "\n| task_type | arm | n | success_rate | avg_cost_usd | avg_wall_ms | stability |\n";
        $md .= "|---|---|---|---|---|---|---|\n";
        foreach ($report['rows'] as $row) {
            $md .= "| {$row['task_type']} | {$row['arm_id']} | {$row['n']} | {$row['success_rate']} | {$row['avg_cost_usd']} | {$row['avg_wall_ms']} | {$row['stability']} |\n";
        }

        return $md."\nEscopo do claim: ".json_encode($report['claim_scope'], JSON_UNESCAPED_SLASHES)."\n";
    }
}
