<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** Berkeley Function Calling Leaderboard (BFCL) — adapter próprio, não tau2. */
class BfclAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'bfcl';
    }

    protected function commandTemplate(): string
    {
        return 'python {atlas_root}/scripts/rivals_bfcl_verboo.py --rivals-test-id={native_test_id} --rivals-category={native_category} generate --model {cli_model} --run-ids --allow-overwrite --num-threads 1 --result-dir {output_parent}/result';
    }

    protected function commandTemplateForArm(array $binding): string
    {
        if (($binding['runtime'] ?? null) === 'atlas_dev') {
            return 'php {atlas_root}/scripts/rivals-bfcl-atlas-unit.php --case-file={case_file} --model={atlas_cli_model} --registry-model={cli_model} --scratch={eval_scratch_dir}';
        }

        return parent::commandTemplateForArm($binding);
    }

    protected function mapResults(array $native): array
    {
        $model = $native['model'] ?? throw new RuntimeException('bfcl_model_missing');
        $receipts = [];
        foreach ($native['results'] ?? [] as $row) {
            $caseId = $row['test_category'] ?? $row['case_id'] ?? throw new RuntimeException('bfcl_case_id_missing');
            $accuracy = $row['accuracy'] ?? null;
            $status = match (true) {
                isset($row['status']) => (string) $row['status'],
                is_numeric($accuracy) => ((float) $accuracy >= 1.0 ? 'success' : 'failure'),
                default => throw new RuntimeException('bfcl_status_or_accuracy_missing'),
            };
            if (! in_array($status, ['success', 'failure', 'error', 'timeout'], true)) {
                throw new RuntimeException('bfcl_invalid_status:'.$status);
            }
            $receipts[] = [
                'case_id' => $caseId,
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($row['run'] ?? $row['repetition'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success' ? null : ($status === 'timeout' ? 'timeout' : 'model_failure'),
                'wall_ms' => (int) ($row['wall_ms'] ?? round(((float) ($row['duration_sec'] ?? 0)) * 1000)),
                'tokens_in' => (int) ($row['tokens_in'] ?? $row['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($row['tokens_out'] ?? $row['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($row['cost_usd'] ?? $row['usage']['cost_usd'] ?? 0.0),
                'field_presence' => (array) ($row['field_presence'] ?? []),
                'started_at' => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'metadata' => [
                    'native' => [
                        'cli_model' => $model,
                        'native_agent' => 'bfcl',
                        'source_repo' => 'bfcl',
                        'accuracy' => $accuracy,
                    ],
                ],
            ];
        }

        return $receipts;
    }
}
