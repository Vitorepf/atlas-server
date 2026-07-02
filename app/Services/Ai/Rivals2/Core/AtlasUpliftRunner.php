<?php

namespace App\Services\Ai\Rivals2\Core;

use App\Services\Ai\Rivals2\Support\RunPaths;
use InvalidArgumentException;

/**
 * Objetivo 2: Atlas+modelo vs modelo puro. Leitor PURO sobre receipts de um
 * run já executado — compara o MESMO modelo em dois runtimes (bare vs
 * atlas_dev/forge/loop) e emite delta escopado por task_type. Se o braço
 * Atlas não rodou, uplift_supported=false — NUNCA simula uplift.
 */
class AtlasUpliftRunner
{
    public function compare(string $runId, string $modelId, string $baseRuntime = 'bare', string $atlasRuntime = 'atlas_dev'): array
    {
        $runtimes = config('atlas_rivals2.runtimes', []);
        if ($baseRuntime === $atlasRuntime || ! in_array($atlasRuntime, $runtimes, true)) {
            throw new InvalidArgumentException("rivals2_uplift_invalid_runtimes:{$baseRuntime}vs{$atlasRuntime}");
        }

        $baseArm = "{$modelId}@{$baseRuntime}";
        $atlasArm = "{$modelId}@{$atlasRuntime}";
        $byArm = ['base' => [], 'atlas' => []];
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            if ($receipt->data['arm_id'] === $baseArm) {
                $byArm['base'][] = $receipt->data;
            } elseif ($receipt->data['arm_id'] === $atlasArm) {
                $byArm['atlas'][] = $receipt->data;
            }
        }

        $adjPath = RunPaths::adjudicationPath($runId);
        $adjudication = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];

        if ($byArm['base'] === [] || $byArm['atlas'] === []) {
            $missing = $byArm['atlas'] === [] ? $atlasArm : $baseArm;

            return $this->persist($runId, [
                'uplift_supported' => false,
                'reason' => "arm_did_not_run:{$missing}",
                'model_id' => $modelId,
                'claim_allowed' => false,
                'claim_blockers' => ["uplift_arm_missing:{$missing}"],
                'deltas' => [],
            ]);
        }

        $deltas = [];
        foreach ($this->metrics($byArm['base']) as $taskType => $base) {
            $atlas = $this->metrics($byArm['atlas'])[$taskType] ?? null;
            if ($atlas === null) {
                continue;
            }
            $deltas[] = [
                'task_type' => $taskType,
                'base' => $base,
                'atlas' => $atlas,
                'delta_success_rate' => round($atlas['success_rate'] - $base['success_rate'], 4),
                'delta_avg_cost_usd' => round($atlas['avg_cost_usd'] - $base['avg_cost_usd'], 6),
                'delta_avg_wall_ms' => $atlas['avg_wall_ms'] - $base['avg_wall_ms'],
            ];
        }

        return $this->persist($runId, [
            'uplift_supported' => true,
            'model_id' => $modelId,
            'base_runtime' => $baseRuntime,
            'atlas_runtime' => $atlasRuntime,
            'deltas' => $deltas,
            // claim de uplift herda a adjudicação do run inteiro
            'claim_allowed' => ($adjudication['claim_allowed'] ?? false) === true,
            'claim_blockers' => $adjudication['claim_blockers'] ?? ['adjudication_missing'],
            'claim_scope' => $adjudication['claim_scope'] ?? null,
        ]);
    }

    /** @return array<string, array{n:int, success_rate:float, avg_cost_usd:float, avg_wall_ms:int}> */
    private function metrics(array $receipts): array
    {
        $groups = [];
        foreach ($receipts as $r) {
            $groups[$r['task_type']][] = $r;
        }
        $out = [];
        foreach ($groups as $taskType => $items) {
            $n = count($items);
            $out[$taskType] = [
                'n' => $n,
                'success_rate' => round(count(array_filter($items, fn ($r) => $r['status'] === 'success')) / $n, 4),
                'avg_cost_usd' => round(array_sum(array_column($items, 'cost_usd')) / $n, 6),
                'avg_wall_ms' => (int) round(array_sum(array_column($items, 'wall_ms')) / $n),
            ];
        }

        return $out;
    }

    private function persist(string $runId, array $result): array
    {
        $result = ['schema_version' => 'atlas.rivals2.uplift.v1', 'run_id' => $runId] + $result
            + ['computed_at' => now()->toIso8601String()];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::runDir($runId).'/uplift.json',
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $result;
    }
}
