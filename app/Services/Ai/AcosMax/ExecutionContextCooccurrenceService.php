<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ExecutionContextCooccurrenceService
{
    public const FIELD_DELIVERED_REFS = 'delivered_refs';
    public const FIELD_FEEDS_ENFORCEMENT = 'feeds_enforcement';
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
    public const FIELD_CONTEXT_CAUSAL_BINDING = 'context_causal_binding';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_OUTCOME_RECEIPT_ID = 'outcome_receipt_id';
    public const FIELD_GREEN_RUN = 'green_run';
    public const FIELD_ENFORCEMENT_ALLOWED = 'enforcement_allowed';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_COOCCURRENCE_COUNT = 'cooccurrence_count';
    public const FIELD_DELIVERED_REF_COUNT = 'delivered_ref_count';
    public const FIELD_REQUIRES_COUNTERFACTUAL_BEFORE_ENFORCEMENT = 'requires_counterfactual_before_enforcement';
    public const FIELD_READ_ONLY = 'read_only';


    public const REASON_MEASURED_SHARE_ZERO = 'measured_share_zero';

    public const REASON_RUN_ARTIFACT_UNAVAILABLE = 'run_artifact_unavailable';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_INTERSECTION_ALONE_IS_NOT_CAUSAL = 'intersection_alone_is_not_causal';
    public const FIELD_INTERSECTION_REFS = 'intersection_refs';
    public const FIELD_MEMORY_WRITTEN = 'memory_written';
    public const FIELD_USED_REF_COUNT = 'used_ref_count';
    public const FIELD_USED_REFS = 'used_refs';
    public const FIELD_CORRELATIONAL_COOCCURRENCE = 'correlational_cooccurrence';
    public const FLOAT_0_0 = 0.0;


    /**
     * @return array<string,mixed>
     */
    public function report(?string $runsPath = null): array
    {
        $base = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_CONTEXT_CAUSAL_BINDING => self::FIELD_CORRELATIONAL_COOCCURRENCE,
            self::FIELD_ENFORCEMENT_ALLOWED => false,
            self::FIELD_REQUIRES_COUNTERFACTUAL_BEFORE_ENFORCEMENT => GoldenCounterfactualReplayService::MEASURE_ID,
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                self::FIELD_FEEDS_ENFORCEMENT => false,
                self::FIELD_INTERSECTION_ALONE_IS_NOT_CAUSAL => true,
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
                    self::FIELD_MEASURED_SHARE => self::FLOAT_0_0,
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
            $delivered = $this->stringList(AiValueNormalizer::arrayOrEmpty($run[self::FIELD_DELIVERED_REFS] ?? null));
            $used = $this->stringList(AiValueNormalizer::arrayOrEmpty($run[self::FIELD_USED_REFS] ?? null));
            $intersection = array_values(array_intersect($delivered, $used));
            if ($intersection === []) {
                continue;
            }
            $cooccurrences[] = [
                self::FIELD_RUN_ID => (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_RUN_ID] ?? null) ?? ''),
                self::FIELD_OUTCOME_RECEIPT_ID => (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_OUTCOME_RECEIPT_ID] ?? null) ?? ''),
                self::FIELD_GREEN_RUN => ($run[self::FIELD_GREEN_RUN] ?? false) === true,
                self::FIELD_DELIVERED_REF_COUNT => count($delivered),
                self::FIELD_USED_REF_COUNT => count($used),
                self::FIELD_INTERSECTION_REFS => $intersection,
                self::FIELD_CONTEXT_CAUSAL_BINDING => self::FIELD_CORRELATIONAL_COOCCURRENCE,
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
            self::FIELD_COOCCURRENCE_COUNT => count($cooccurrences),
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
