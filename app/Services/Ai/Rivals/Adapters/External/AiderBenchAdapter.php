<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** aider polyglot benchmark: exercícios Exercism multi-linguagem, edit-format diff. */
class AiderBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'aider_polyglot';
    }

    protected function commandTemplate(): string
    {
        return './benchmark/benchmark.py {case_id} --model {model} --edit-format diff --tries 2';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['model'] ?? 'unknown';
        $receipts = [];
        foreach ($native['results'] ?? [] as $r) {
            $testcase = $r['testcase'] ?? throw new RuntimeException('aider_polyglot_testcase_missing');
            $outcomes = $r['tests_outcomes'] ?? null;
            if (! is_array($outcomes) || $outcomes === []) {
                throw new RuntimeException('aider_polyglot_tests_outcomes_missing:'.$testcase);
            }
            $receipts[] = [
                'case_id' => $testcase,
                'task_type' => 'coding_patch',
                'arm_id' => $model.'@aider',
                // tries do aider são retries internos do harness, não repetitions Rivals
                'repetition' => 1,
                'status' => end($outcomes) === true ? 'success' : 'failure',
                'wall_ms' => (int) round((float) ($r['duration'] ?? 0) * 1000),
                'tokens_in' => (int) ($r['sent_tokens'] ?? 0),
                'tokens_out' => (int) ($r['received_tokens'] ?? 0),
                'cost_usd' => (float) ($r['cost'] ?? 0.0),
                'started_at' => $r['start_time'] ?? null,
                'finished_at' => $r['end_time'] ?? null,
            ];
        }

        return $receipts;
    }
}
