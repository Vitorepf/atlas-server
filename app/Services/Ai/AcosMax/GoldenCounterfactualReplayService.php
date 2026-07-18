<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class GoldenCounterfactualReplayService
{
    public const SCHEMA_VERSION = 'atlas.context.golden_counterfactual.v1';

    public const MEASURE_ID = 'atlas.context.golden_counterfactual.v1';

    public const FORMULA_VERSION = 'atlas_context_golden_counterfactual_v1';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_OK = 'ok';

    public const REASON_PAIRED_ARMS_MISSING = 'paired_arms_missing';

    public const REASON_PAIRED_GOLDEN_RUNS_UNAVAILABLE = 'paired_golden_runs_unavailable';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_DECISION_ID = 'decision_id';
    public const FIELD_RUNS_PATH = 'runs_path';
    public const FIELD_COUNTERFACTUAL = 'counterfactual';
    public const FIELD_WITHOUT = 'without';
    public const FIELD_WITH = 'with';
    public const FIELD_RECALL_AT_5 = 'recall_at_5';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_ARM = 'arm';
    public const FIELD_COMMIT = 'commit';
    public const FIELD_EXECUTED_AT = 'executed_at';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_READ_ONLY = 'read_only';
    public const FIELD_RECALL_AT_5_WITHOUT = 'recall_at_5_without';
    public const FIELD_RECALL_AT_5_WITH = 'recall_at_5_with';
    public const FIELD_RUNS = 'runs';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_DELTA = 'delta';
    public const FIELD_DELTA_REQUIRES_BOTH_ARMS = 'delta_requires_both_arms';


    /**
     * @return array<string,mixed>
     */
    public function report(?string $runsPath = null, ?string $decisionId = null): array
    {
        $base = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            'generated_at' => now()->toIso8601String(),
            self::FIELD_DECISION_ID => $decisionId,
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                'git_checkout_performed' => false,
                'extrapolation_allowed' => false,
                self::FIELD_DELTA_REQUIRES_BOTH_ARMS => true,
            ],
        ];

        if ($runsPath === null || (AiValueNormalizer::trimmedStringOrNull($runsPath) ?? '') === '' || ! is_file($runsPath)) {
            return array_replace($base, [
                self::FIELD_STATUS => self::STATUS_SKIPPED,
                self::FIELD_REASON => self::REASON_PAIRED_GOLDEN_RUNS_UNAVAILABLE,
                self::FIELD_RUNS_PATH => $runsPath,
                self::FIELD_COUNTERFACTUAL => [
                    self::FIELD_WITHOUT => null,
                    self::FIELD_WITH => null,
                ],
            ]);
        }

        $payload = $this->readJson($runsPath);
        $runs = array_values(AiValueNormalizer::arrayOrEmpty($payload[self::FIELD_RUNS] ?? null));
        $without = $this->firstRun($runs, 'without', $decisionId);
        $with = $this->firstRun($runs, 'with', $decisionId);

        if ($without === null || $with === null) {
            return array_replace($base, [
                self::FIELD_STATUS => self::STATUS_SKIPPED,
                self::FIELD_REASON => self::REASON_PAIRED_ARMS_MISSING,
                self::FIELD_RUNS_PATH => $runsPath,
                self::FIELD_COUNTERFACTUAL => [
                    self::FIELD_WITHOUT => $without,
                    self::FIELD_WITH => $with,
                ],
            ]);
        }

        return array_replace($base, [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_REASON => null,
            self::FIELD_RUNS_PATH => $runsPath,
            self::FIELD_COUNTERFACTUAL => [
                self::FIELD_WITHOUT => $without,
                self::FIELD_WITH => $with,
                'metric' => 'recall_at_5',
                self::FIELD_RECALL_AT_5_WITHOUT => $without[self::FIELD_RECALL_AT_5],
                self::FIELD_RECALL_AT_5_WITH => $with[self::FIELD_RECALL_AT_5],
                self::FIELD_DELTA => round(
                    (AiValueNormalizer::finiteFloatOrNull($with[self::FIELD_RECALL_AT_5] ?? null) ?? 0.0)
                    - (AiValueNormalizer::finiteFloatOrNull($without[self::FIELD_RECALL_AT_5] ?? null) ?? 0.0),
                    6
                ),
            ],
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
     * @param  list<mixed>  $runs
     * @return array<string,mixed>|null
     */
    private function firstRun(array $runs, string $arm, ?string $decisionId): ?array
    {
        foreach ($runs as $run) {
            if (! is_array($run) || (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_ARM] ?? null) ?? '') !== $arm) {
                continue;
            }
            if ($decisionId !== null && $decisionId !== '' && (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_DECISION_ID] ?? null) ?? '') !== $decisionId) {
                continue;
            }
            if (AiValueNormalizer::finiteFloatOrNull($run[self::FIELD_RECALL_AT_5] ?? null) === null) {
                continue;
            }
            $commit = AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_COMMIT] ?? null) ?? '';
            $runId = AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_RUN_ID] ?? null) ?? '';
            if ($commit === '' || $runId === '') {
                continue;
            }

            return [
                self::FIELD_ARM => $arm,
                self::FIELD_DECISION_ID => (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_DECISION_ID] ?? null) ?? ''),
                self::FIELD_RUN_ID => $runId,
                self::FIELD_COMMIT => $commit,
                self::FIELD_RECALL_AT_5 => round(AiValueNormalizer::clampUnit(
                    AiValueNormalizer::finiteFloatOrNull($run[self::FIELD_RECALL_AT_5]) ?? 0.0
                ), 6),
                self::FIELD_EXECUTED_AT => (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_EXECUTED_AT] ?? null) ?? ''),
            ];
        }

        return null;
    }
}
