<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter comum para suites nativas de engenharia.
 *
 * Cada suite conserva seu dataset e avaliador oficiais. O executor comum só
 * garante que bare e Atlas produzam o mesmo artefato congelado e que o resultado
 * chegue ao ledger com identidade, usage e causa de falha verificáveis.
 */
final class EngineeringNativeSuiteAdapter extends AbstractExternalSuiteAdapter
{
    /** @var list<string> */
    public const SUITE_IDS = [
        'archbench',
        'cruxeval',
        'classeval',
        'repobench',
        'locagent',
        'debug_gym',
        'testeval',
        'evalplus',
        'crosscodeeval',
        'bigcodebench',
        'deveval',
        'long_code_arena',
        'reval',
    ];

    /** @var array<string, string> */
    private const CONTINUOUS_PRIMARY_METRICS = [
        'archbench' => 'rougeL',
    ];

    public function __construct(private readonly string $id)
    {
        if (! in_array($id, self::SUITE_IDS, true)) {
            throw new InvalidArgumentException("unknown_engineering_native_suite:{$id}");
        }
    }

    public function suiteId(): string
    {
        return $this->id;
    }

    protected function commandTemplate(): string
    {
        return 'php {atlas_root}/scripts/rivals-engineering-unit.php --suite={suite_id} --case-file={case_file} --model={atlas_cli_model} --registry-model={cli_model} --runtime={runtime} --scratch={eval_scratch_dir} --rep={rep}';
    }

    /** @return array<int, array<string, mixed>> */
    protected function mapResults(array $native): array
    {
        if (($native['schema_version'] ?? null) !== 'atlas.rivals2.engineering_native_unit.v1') {
            throw new RuntimeException($this->id.'_engineering_native_schema_invalid');
        }
        if (($native['suite_id'] ?? null) !== $this->id) {
            throw new RuntimeException($this->id.'_engineering_native_suite_mismatch');
        }
        $model = trim((string) ($native['model'] ?? ''));
        if ($model === '') {
            throw new RuntimeException($this->id.'_engineering_native_model_missing');
        }

        $receipts = [];
        foreach ((array) ($native['results'] ?? []) as $row) {
            $caseId = trim((string) ($row['case_id'] ?? ''));
            $taskType = trim((string) ($row['task_type'] ?? ''));
            $status = (string) ($row['status'] ?? '');
            if ($caseId === '' || $taskType === '') {
                throw new RuntimeException($this->id.'_engineering_native_identity_missing');
            }
            if (! in_array($status, ['success', 'failure', 'error', 'timeout'], true)) {
                throw new RuntimeException($this->id.'_engineering_native_status_invalid:'.$status);
            }
            $tokensIn = (int) ($row['tokens_in'] ?? 0);
            $tokensOut = (int) ($row['tokens_out'] ?? 0);
            $hasUsage = $tokensIn > 0 && $tokensOut > 0;
            $failureClass = $status === 'success'
                ? null
                : (string) ($row['failure_class'] ?? ($status === 'timeout' ? 'timeout' : 'model_failure'));
            $failureReason = $status === 'success'
                ? null
                : trim((string) ($row['failure_reason'] ?? ''));
            if ($status !== 'success' && $failureReason === '') {
                throw new RuntimeException($this->id.'_engineering_native_failure_reason_missing');
            }
            $measurementType = trim((string) ($row['measurement_type']
                ?? (isset(self::CONTINUOUS_PRIMARY_METRICS[$this->id])
                    ? 'continuous'
                    : 'binary')));
            $scoreMetric = trim((string) ($row['score_metric']
                ?? self::CONTINUOUS_PRIMARY_METRICS[$this->id]
                ?? 'success'));
            if (! in_array($measurementType, ['binary', 'continuous'], true)
                || ($measurementType === 'continuous'
                    && (! is_numeric($row['score'] ?? null) || $scoreMetric === ''))) {
                throw new RuntimeException(
                    $this->id.'_engineering_native_measurement_contract_invalid',
                );
            }

            $receipts[] = [
                'case_id' => $caseId,
                'task_type' => $taskType,
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($row['repetition'] ?? 0),
                'status' => $status,
                'failure_class' => $failureClass,
                'failure_reason' => $failureReason,
                'wall_ms' => (int) ($row['wall_ms'] ?? 0),
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd' => (float) ($row['cost_usd'] ?? 0.0),
                'field_presence' => [
                    'tokens_in' => [
                        'present' => $hasUsage,
                        'reason' => $hasUsage ? null : 'engineering_native_usage_not_reported',
                    ],
                    'tokens_out' => [
                        'present' => $hasUsage,
                        'reason' => $hasUsage ? null : 'engineering_native_usage_not_reported',
                    ],
                    'cost_usd' => [
                        'present' => $hasUsage,
                        'reason' => $hasUsage
                            ? 'verboo_subscription_marginal'
                            : 'engineering_native_usage_not_reported',
                    ],
                ],
                'started_at' => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'metadata' => [
                    'native' => [
                        'cli_model' => $model,
                        'native_agent' => 'engineering_native',
                        'source_repo' => $this->id,
                        'measurement_type' => $measurementType,
                        'score_metric' => $scoreMetric,
                        'score' => $row['score'] ?? null,
                        'native_metrics' => (array) ($row['native_metrics'] ?? []),
                        'native_artifact' => (array) ($row['native_artifact'] ?? []),
                    ],
                ],
            ];
        }
        if (count($receipts) !== 1) {
            throw new RuntimeException($this->id.'_engineering_native_unit_result_cardinality');
        }

        return $receipts;
    }
}
