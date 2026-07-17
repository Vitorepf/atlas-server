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


    /**
     * @return array<string,mixed>
     */
    public function report(?string $runsPath = null, ?string $decisionId = null): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'decision_id' => $decisionId,
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
                'status' => self::STATUS_SKIPPED,
                'reason' => self::REASON_PAIRED_GOLDEN_RUNS_UNAVAILABLE,
                'runs_path' => $runsPath,
                'counterfactual' => [
                    'without' => null,
                    'with' => null,
                ],
            ]);
        }

        $payload = $this->readJson($runsPath);
        $runs = array_values(AiValueNormalizer::arrayOrEmpty($payload['runs'] ?? null));
        $without = $this->firstRun($runs, 'without', $decisionId);
        $with = $this->firstRun($runs, 'with', $decisionId);

        if ($without === null || $with === null) {
            return array_replace($base, [
                'status' => self::STATUS_SKIPPED,
                'reason' => self::REASON_PAIRED_ARMS_MISSING,
                'runs_path' => $runsPath,
                'counterfactual' => [
                    'without' => $without,
                    'with' => $with,
                ],
            ]);
        }

        return array_replace($base, [
            'status' => self::STATUS_OK,
            'reason' => null,
            'runs_path' => $runsPath,
            'counterfactual' => [
                'without' => $without,
                'with' => $with,
                'metric' => 'recall_at_5',
                'recall_at_5_without' => $without['recall_at_5'],
                'recall_at_5_with' => $with['recall_at_5'],
                'delta' => round(
                    (AiValueNormalizer::finiteFloatOrNull($with['recall_at_5'] ?? null) ?? 0.0)
                    - (AiValueNormalizer::finiteFloatOrNull($without['recall_at_5'] ?? null) ?? 0.0),
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
            if ($decisionId !== null && $decisionId !== '' && (AiValueNormalizer::trimmedStringOrNull($run['decision_id'] ?? null) ?? '') !== $decisionId) {
                continue;
            }
            if (AiValueNormalizer::finiteFloatOrNull($run['recall_at_5'] ?? null) === null) {
                continue;
            }
            $commit = AiValueNormalizer::trimmedStringOrNull($run['commit'] ?? null) ?? '';
            $runId = AiValueNormalizer::trimmedStringOrNull($run['run_id'] ?? null) ?? '';
            if ($commit === '' || $runId === '') {
                continue;
            }

            return [
                'arm' => $arm,
                'decision_id' => (AiValueNormalizer::trimmedStringOrNull($run['decision_id'] ?? null) ?? ''),
                'run_id' => $runId,
                'commit' => $commit,
                'recall_at_5' => round(AiValueNormalizer::clampUnit(
                    AiValueNormalizer::finiteFloatOrNull($run['recall_at_5']) ?? 0.0
                ), 6),
                'executed_at' => (AiValueNormalizer::trimmedStringOrNull($run['executed_at'] ?? null) ?? ''),
            ];
        }

        return null;
    }
}
