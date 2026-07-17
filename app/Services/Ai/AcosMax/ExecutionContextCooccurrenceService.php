<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ExecutionContextCooccurrenceService
{
    public const SCHEMA_VERSION = 'atlas.context.execution_cooccurrence.v1';

    public const MEASURE_ID = 'atlas.context.execution_cooccurrence.v1';

    public const FORMULA_VERSION = 'atlas_context_execution_cooccurrence_v1';

    public const STATUS_UNMEASURABLE = 'unmeasurable';

    public const STATUS_OK = 'ok';

    public const FIELD_MEASURED = 'measured';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_RUNS_PATH = 'runs_path';
    public const FIELD_DENOMINATOR = 'denominator';
    public const FIELD_RUNS = 'runs';
    public const FIELD_MEASURED_RUNS = 'measured_runs';
    public const FIELD_MEASURED_SHARE = 'measured_share';
    public const FIELD_COOCCURRENCES = 'cooccurrences';


    public const REASON_MEASURED_SHARE_ZERO = 'measured_share_zero';

    public const REASON_RUN_ARTIFACT_UNAVAILABLE = 'run_artifact_unavailable';


    /**
     * @return array<string,mixed>
     */
    public function report(?string $runsPath = null): array
    {
        $base = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'context_causal_binding' => 'correlational_cooccurrence',
            'enforcement_allowed' => false,
            'requires_counterfactual_before_enforcement' => GoldenCounterfactualReplayService::MEASURE_ID,
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'feeds_enforcement' => false,
                'intersection_alone_is_not_causal' => true,
            ],
        ];

        if ($runsPath === null || (AiValueNormalizer::trimmedStringOrNull($runsPath) ?? '') === '' || ! is_file($runsPath)) {
            return array_replace($base, [
                self::FIELD_STATUS => self::STATUS_UNMEASURABLE,
                self::FIELD_REASON => self::REASON_RUN_ARTIFACT_UNAVAILABLE,
                self::FIELD_RUNS_PATH => $runsPath,
                self::FIELD_DENOMINATOR => [
                    self::FIELD_RUNS => 0,
                    self::FIELD_MEASURED_RUNS => 0,
                    self::FIELD_MEASURED_SHARE => 0.0,
                ],
                self::FIELD_COOCCURRENCES => [],
            ]);
        }

        $runs = array_values(AiValueNormalizer::arrayOrEmpty($this->readJson($runsPath)[self::FIELD_RUNS] ?? null));
        $measured = [];
        foreach ($runs as $run) {
            if (! is_array($run) || ($run[self::FIELD_MEASURED] ?? false) !== true) {
                continue;
            }
            $measured[] = $run;
        }

        $runCount = count($runs);
        $measuredCount = count($measured);
        $measuredShare = $runCount > 0 ? round($measuredCount / $runCount, 6) : 0.0;
        if ($measuredCount === 0) {
            return array_replace($base, [
                self::FIELD_STATUS => self::STATUS_UNMEASURABLE,
                self::FIELD_REASON => self::REASON_MEASURED_SHARE_ZERO,
                self::FIELD_RUNS_PATH => $runsPath,
                self::FIELD_DENOMINATOR => [
                    self::FIELD_RUNS => $runCount,
                    self::FIELD_MEASURED_RUNS => 0,
                    self::FIELD_MEASURED_SHARE => $measuredShare,
                ],
                self::FIELD_COOCCURRENCES => [],
            ]);
        }

        $cooccurrences = [];
        foreach ($measured as $run) {
            $delivered = $this->stringList(AiValueNormalizer::arrayOrEmpty($run['delivered_refs'] ?? null));
            $used = $this->stringList(AiValueNormalizer::arrayOrEmpty($run['used_refs'] ?? null));
            $intersection = array_values(array_intersect($delivered, $used));
            if ($intersection === []) {
                continue;
            }
            $cooccurrences[] = [
                'run_id' => (AiValueNormalizer::trimmedStringOrNull($run['run_id'] ?? null) ?? ''),
                'outcome_receipt_id' => (AiValueNormalizer::trimmedStringOrNull($run['outcome_receipt_id'] ?? null) ?? ''),
                'green_run' => ($run['green_run'] ?? false) === true,
                'delivered_ref_count' => count($delivered),
                'used_ref_count' => count($used),
                'intersection_refs' => $intersection,
                'context_causal_binding' => 'correlational_cooccurrence',
            ];
        }

        return array_replace($base, [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_REASON => null,
            self::FIELD_RUNS_PATH => $runsPath,
            self::FIELD_DENOMINATOR => [
                self::FIELD_RUNS => $runCount,
                self::FIELD_MEASURED_RUNS => $measuredCount,
                self::FIELD_MEASURED_SHARE => $measuredShare,
            ],
            'cooccurrence_count' => count($cooccurrences),
            self::FIELD_COOCCURRENCES => $cooccurrences,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if (AiValueNormalizer::trimmedStringOrNull($raw) === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = AiValueNormalizer::trimmedStringOrNull($value) ?? '';
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        sort($out);

        return $out;
    }
}
