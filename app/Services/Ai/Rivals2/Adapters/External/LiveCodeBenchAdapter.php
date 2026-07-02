<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use RuntimeException;

/** LiveCodeBench: problemas de código contamination-free por release window. */
class LiveCodeBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'live_code_bench';
    }

    protected function commandTemplate(): string
    {
        return 'python -m lcb_runner.runner.main --model {model} --scenario codegeneration --release_version release_v6 --question_ids {case_id}';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['model'] ?? 'unknown';
        $receipts = [];
        foreach ($native['results'] ?? [] as $r) {
            $questionId = $r['question_id'] ?? throw new RuntimeException('live_code_bench_question_id_missing');
            $graded = $r['graded_list'] ?? null;
            if (! is_array($graded) || $graded === []) {
                throw new RuntimeException('live_code_bench_graded_list_missing:'.$questionId);
            }
            $receipts[] = [
                'case_id' => $questionId,
                'task_type' => 'coding_patch',
                'arm_id' => $model.'@lcb',
                'repetition' => 1,
                'status' => $graded[0] === true ? 'success' : 'failure',
                // LCB não reporta tempo/tokens/custo por questão; 0 honesto, nunca estimado
                'wall_ms' => 0,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_usd' => 0.0,
                'started_at' => null,
                'finished_at' => null,
            ];
        }

        return $receipts;
    }
}
