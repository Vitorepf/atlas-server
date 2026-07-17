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
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'git_checkout_performed' => false,
                'extrapolation_allowed' => false,
                'delta_requires_both_arms' => true,
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
        $runs = array_values(AiValueNormalizer::arrayOrEmpty($payload['runs'] ?? null));
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
                'recall_at_5_without' => $without[self::FIELD_RECALL_AT_5],
                'recall_at_5_with' => $with[self::FIELD_RECALL_AT_5],
                'delta' => round(
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
            if (! is_array($run) || (AiValueNormalizer::trimmedStringOrNull($run['arm'] ?? null) ?? '') !== $arm) {
                continue;
            }
            if ($decisionId !== null && $decisionId !== '' && (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_DECISION_ID] ?? null) ?? '') !== $decisionId) {
                continue;
            }
            if (AiValueNormalizer::finiteFloatOrNull($run[self::FIELD_RECALL_AT_5] ?? null) === null) {
                continue;
            }
            $commit = AiValueNormalizer::trimmedStringOrNull($run['commit'] ?? null) ?? '';
            $runId = AiValueNormalizer::trimmedStringOrNull($run['run_id'] ?? null) ?? '';
            if ($commit === '' || $runId === '') {
                continue;
            }

            return [
                'arm' => $arm,
                self::FIELD_DECISION_ID => (AiValueNormalizer::trimmedStringOrNull($run[self::FIELD_DECISION_ID] ?? null) ?? ''),
                'run_id' => $runId,
                'commit' => $commit,
                self::FIELD_RECALL_AT_5 => round(AiValueNormalizer::clampUnit(
                    AiValueNormalizer::finiteFloatOrNull($run[self::FIELD_RECALL_AT_5]) ?? 0.0
                ), 6),
                'executed_at' => (AiValueNormalizer::trimmedStringOrNull($run['executed_at'] ?? null) ?? ''),
            ];
        }

        return null;
    }
}
