<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Batches amplifier regression cases from recent weak outputs so
 * repair tasks cover recurring failures instead of anecdotes.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainAmplifierRegressionReplayBatcher
{
    public const SCHEMA = 'atlas.self_construction.external_brain_amplifier_regression_replay_batcher.v1';

    public const RECURRING_THRESHOLD = 2;
    public const ONE_OFF_SAMPLE_LIMIT = 1;

    /**
     * @param  array<int, array<string, mixed>>  $weakOutputs
     * @return array<string, mixed>
     */
    public function batch(array $weakOutputs): array
    {
        $failurePatterns = [];
        $recurring = [];
        $oneOffs = [];

        // Group by failure pattern
        foreach ($weakOutputs as $output) {
            if (! is_array($output)) {
                continue;
            }
            $pattern = (string) ($output['failure_pattern'] ?? 'unknown');
            $failurePatterns[$pattern][] = $output;
        }

        foreach ($failurePatterns as $pattern => $cases) {
            if (count($cases) >= self::RECURRING_THRESHOLD) {
                $recurring[] = [
                    'failure_pattern' => $pattern,
                    'case_count' => count($cases),
                    'cases' => $cases,
                    'replay_acceptance' => 'replay all cases and verify fix',
                ];
            } else {
                // Sample one-offs
                $oneOffs[] = [
                    'failure_pattern' => $pattern,
                    'case_count' => count($cases),
                    'cases' => array_slice($cases, 0, self::ONE_OFF_SAMPLE_LIMIT),
                    'replay_acceptance' => 'replay sampled case and verify fix',
                ];
            }
        }

        $batches = array_merge($recurring, $oneOffs);

        return [
            'schema' => self::SCHEMA,
            'batches' => $batches,
            'batch_count' => count($batches),
            'recurring_count' => count($recurring),
            'one_off_count' => count($oneOffs),
            'total_weak_outputs' => count($weakOutputs),
        ];
    }
}
