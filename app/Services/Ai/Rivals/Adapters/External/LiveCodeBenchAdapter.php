<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** LiveCodeBench. */
class LiveCodeBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'live_code_bench';
    }

    protected function commandTemplate(): string
    {
        return 'python {atlas_root}/scripts/rivals_lcb_verboo.py --rivals-question-id={native_task_id} --rivals-usage-file={eval_scratch_dir}/provider_usage.json --model {cli_model} --scenario codegeneration --release_version release_v6 --evaluate --continue_existing_with_eval --n 1 --temperature {temperature} --multiprocess 1 --num_process_evaluate 1';
    }

    protected function commandTemplateForArm(array $binding): string
    {
        // Braço com-Atlas: mesmo harness/avaliador, geração via bridge
        // governado (scripts/rivals_lcb_atlas.py).
        if (($binding['runtime'] ?? 'bare') === 'atlas_dev') {
            return str_replace(
                'rivals_lcb_verboo.py',
                'rivals_lcb_atlas.py',
                $this->commandTemplate(),
            );
        }

        return parent::commandTemplateForArm($binding);
    }

    protected function mapResults(array $native): array
    {
        $model = $native['model'] ?? throw new RuntimeException('live_code_bench_model_missing');
        $receipts = [];
        foreach ($native['results'] ?? [] as $r) {
            $questionId = $r['question_id'] ?? throw new RuntimeException('live_code_bench_question_id_missing');
            $graded = $r['graded_list'] ?? null;
            if (! is_array($graded) || $graded === []) {
                throw new RuntimeException('live_code_bench_graded_list_missing:'.$questionId);
            }
            $status = $graded[0] === true ? 'success' : 'failure';
            $hasTiming = isset($r['wall_ms']) || isset($r['duration_sec']);
            $hasUsage = (($r['usage_capture']['present'] ?? null) === true)
                || (isset($r['tokens_in'], $r['tokens_out'])
                    && ((int) $r['tokens_in'] + (int) $r['tokens_out']) > 0);
            $usageMissingReason = (string) ($r['usage_capture']['reason'] ?? 'lcb_omits_usage');
            $receipts[] = [
                'case_id' => $questionId,
                'task_type' => 'coding_patch',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($r['repetition'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success' ? null : 'model_failure',
                'wall_ms' => (int) ($r['wall_ms'] ?? round(((float) ($r['duration_sec'] ?? 0)) * 1000)),
                'tokens_in' => (int) ($r['tokens_in'] ?? 0),
                'tokens_out' => (int) ($r['tokens_out'] ?? 0),
                'cost_usd' => (float) ($r['cost_usd'] ?? 0.0),
                'field_presence' => [
                    'wall_ms' => ['present' => $hasTiming, 'reason' => $hasTiming ? null : 'lcb_omits_per_question_timing'],
                    'tokens_in' => ['present' => $hasUsage, 'reason' => $hasUsage ? null : $usageMissingReason],
                    'tokens_out' => ['present' => $hasUsage, 'reason' => $hasUsage ? null : $usageMissingReason],
                    'cost_usd' => [
                        'present' => $hasUsage,
                        'reason' => $hasUsage ? 'verboo_subscription_marginal' : $usageMissingReason,
                    ],
                ],
                'started_at' => $r['started_at'] ?? null,
                'finished_at' => $r['finished_at'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => 'lcb',
                    'source_repo' => 'live_code_bench',
                ]],
            ];
        }

        return $receipts;
    }
}
