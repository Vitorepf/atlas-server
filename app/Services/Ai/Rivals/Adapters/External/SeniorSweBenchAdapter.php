<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/**
 * Senior SWE-Bench (Snorkel, roda via Harbor). Só o subset público de 50 tasks.
 * Verdicts por dimensão NUNCA são colapsados num score único — entram crus no
 * receipt como 'dimensions'. Sem judge_config (VA model + judge + classifier)
 * o resultado não é auditável → fail-closed.
 */
class SeniorSweBenchAdapter extends AbstractExternalSuiteAdapter
{
    public const COVERAGE = 'public_50_only';

    public function suiteId(): string
    {
        return 'senior_swe_bench';
    }

    protected function commandTemplate(): string
    {
        return 'harbor run --repo snorkel-ai/senior-swe-bench-v2026.06 -a {arm_id} -m {model} --task {case_id}';
    }

    protected function mapResults(array $native): array
    {
        $judge = $native['judge_config'] ?? null;
        if (! is_array($judge) || $judge === []) {
            throw new RuntimeException('senior_swe_bench_judge_config_missing');
        }
        if (($native['coverage'] ?? null) !== self::COVERAGE) {
            throw new RuntimeException('senior_swe_bench_coverage_mismatch:expected='.self::COVERAGE);
        }

        $receipts = [];
        foreach ($native['tasks'] ?? [] as $task) {
            $taskId = $task['task_id'] ?? throw new RuntimeException('senior_swe_bench_task_id_missing');
            $dimensions = $task['verdicts'] ?? null;
            if (! is_array($dimensions)) {
                throw new RuntimeException('senior_swe_bench_verdicts_missing:'.$taskId);
            }
            $taskType = match ($task['task'] ?? null) {
                'feature' => 'feature_under_specified',
                'bug' => 'bug_investigation',
                default => throw new RuntimeException('senior_swe_bench_unknown_task_kind:'.$taskId),
            };

            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $taskType,
                'arm_id' => ($task['model'] ?? 'unknown').'@'.($task['agent'] ?? 'unknown'),
                'repetition' => (int) ($task['attempt'] ?? 1),
                'status' => isset($task['resolved']) ? ($task['resolved'] ? 'success' : 'failure') : 'error',
                'wall_ms' => (int) round((float) ($task['duration_seconds'] ?? 0) * 1000),
                'tokens_in' => (int) ($task['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($task['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($task['usage']['cost_usd'] ?? 0.0),
                'started_at' => $task['started_at'] ?? null,
                'finished_at' => $task['finished_at'] ?? null,
                // dimensões cruas + judge_config: sem colapso em score único
                'dimensions' => $dimensions,
                'judge_config' => $judge,
            ];
        }

        return $receipts;
    }
}
