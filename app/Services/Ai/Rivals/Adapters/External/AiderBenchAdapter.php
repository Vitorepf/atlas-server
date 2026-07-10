<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** aider polyglot benchmark. */
class AiderBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'aider_polyglot';
    }

    protected function commandTemplate(): string
    {
        return 'benchmark/benchmark.py {run_name} --new --model {cli_model} --edit-format diff --tries 2 --keywords {native_task_id} --num-tests 1 --threads 1 --exercises-dir polyglot-benchmark';
    }

    protected function commandTemplateForArm(array $binding): string
    {
        if (($binding['runtime'] ?? null) === 'atlas_dev') {
            return 'php {atlas_root}/scripts/rivals-aider-polyglot-unit.php --case-file={case_file} --model={atlas_cli_model} --scratch={eval_scratch_dir}';
        }

        return parent::commandTemplateForArm($binding);
    }

    protected function mapResults(array $native): array
    {
        $model = $native['model'] ?? throw new RuntimeException('aider_polyglot_model_missing');
        $receipts = [];
        foreach ($native['results'] ?? [] as $r) {
            $testcase = $r['testcase'] ?? throw new RuntimeException('aider_polyglot_testcase_missing');
            $outcomes = $r['tests_outcomes'] ?? null;
            if (! is_array($outcomes) || $outcomes === []) {
                throw new RuntimeException('aider_polyglot_tests_outcomes_missing:'.$testcase);
            }
            $status = end($outcomes) === true ? 'success' : 'failure';
            $receipts[] = [
                'case_id' => $testcase,
                'task_type' => 'coding_patch',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($r['repetition'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success' ? null : 'model_failure',
                'wall_ms' => (int) round((float) ($r['duration'] ?? 0) * 1000),
                'tokens_in' => (int) ($r['sent_tokens'] ?? 0),
                'tokens_out' => (int) ($r['received_tokens'] ?? 0),
                'cost_usd' => (float) ($r['cost'] ?? 0.0),
                'started_at' => $r['start_time'] ?? null,
                'finished_at' => $r['end_time'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => 'aider',
                    'source_repo' => 'aider_polyglot',
                ]],
            ];
        }

        return $receipts;
    }
}
