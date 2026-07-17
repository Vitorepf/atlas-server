<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class GoldenCounterfactualReplayService
{
    public const SCHEMA_VERSION = 'atlas.context.golden_counterfactual.v1';

    public const MEASURE_ID = 'atlas.context.golden_counterfactual.v1';

    public const FORMULA_VERSION = 'atlas_context_golden_counterfactual_v1';

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

        if ($runsPath === null || AiValueNormalizer::trimmedString($runsPath) === '' || ! is_file($runsPath)) {
            return array_replace($base, [
                'status' => 'skipped',
                'reason' => 'paired_golden_runs_unavailable',
                'runs_path' => $runsPath,
                'counterfactual' => [
                    'without' => null,
                    'with' => null,
                ],
            ]);
        }

        $payload = $this->readJson($runsPath);
        $runs = array_values((array) ($payload['runs'] ?? []));
        $without = $this->firstRun($runs, 'without', $decisionId);
        $with = $this->firstRun($runs, 'with', $decisionId);

        if ($without === null || $with === null) {
            return array_replace($base, [
                'status' => 'skipped',
                'reason' => 'paired_arms_missing',
                'runs_path' => $runsPath,
                'counterfactual' => [
                    'without' => $without,
                    'with' => $with,
                ],
            ]);
        }

        return array_replace($base, [
            'status' => 'ok',
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
            if (! is_array($run) || (string) ($run['arm'] ?? '') !== $arm) {
                continue;
            }
            if ($decisionId !== null && $decisionId !== '' && (string) ($run['decision_id'] ?? '') !== $decisionId) {
                continue;
            }
            if (AiValueNormalizer::finiteFloatOrNull($run['recall_at_5'] ?? null) === null) {
                continue;
            }
            $commit = AiValueNormalizer::trimmedString($run['commit'] ?? '');
            $runId = AiValueNormalizer::trimmedString($run['run_id'] ?? '');
            if ($commit === '' || $runId === '') {
                continue;
            }

            return [
                'arm' => $arm,
                'decision_id' => (string) ($run['decision_id'] ?? ''),
                'run_id' => $runId,
                'commit' => $commit,
                'recall_at_5' => round(AiValueNormalizer::clampUnit(
                    AiValueNormalizer::finiteFloatOrNull($run['recall_at_5']) ?? 0.0
                ), 6),
                'executed_at' => (string) ($run['executed_at'] ?? ''),
            ];
        }

        return null;
    }
}
