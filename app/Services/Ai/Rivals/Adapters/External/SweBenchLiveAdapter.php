<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/**
 * SWE-bench-Live: instâncias frescas de issues reais. Default honesto de
 * task_type = repair_regression_fixing (issue-fixing); case importado pode
 * sobrescrever para coding_patch.
 */
class SweBenchLiveAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'swe_bench_live';
    }

    protected function commandTemplate(): string
    {
        return 'python -m swebench.harness.run_evaluation --dataset SWE-bench-Live/SWE-bench-Live --instance_ids {case_id} --predictions_path predictions.jsonl --run_id {rep}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['instances'] ?? [] as $inst) {
            $instanceId = $inst['instance_id'] ?? throw new RuntimeException('swe_bench_live_instance_id_missing');
            $receipts[] = [
                'case_id' => $instanceId,
                'task_type' => $this->caseTaskType($instanceId) ?? 'repair_regression_fixing',
                'arm_id' => ($inst['model_name_or_path'] ?? 'unknown').'@swebench',
                'repetition' => 1,
                'status' => isset($inst['resolved']) ? ($inst['resolved'] ? 'success' : 'failure') : 'error',
                'wall_ms' => (int) round((float) ($inst['duration_sec'] ?? 0) * 1000),
                // o harness de avaliação SWE-bench não reporta tokens/custo do agente; 0 honesto
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_usd' => 0.0,
                'started_at' => $inst['started_at'] ?? null,
                'finished_at' => $inst['finished_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
