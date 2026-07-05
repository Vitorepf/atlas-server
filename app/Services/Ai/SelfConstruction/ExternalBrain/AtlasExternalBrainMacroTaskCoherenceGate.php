<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Rejects originator batches that are padding, single-test farms,
 * cosmetic wrapper farms, or same-organ saturation without an
 * implementation-and-test pair per task.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainMacroTaskCoherenceGate
{
    public const SCHEMA = 'atlas.self_construction.external_brain_macro_task_coherence_gate.v1';

    public const REASON_PADDING = 'padding_batch';
    public const REASON_TEST_ONLY_FARM = 'test_only_farm';
    public const REASON_WRAPPER_ONLY_FARM = 'wrapper_only_farm';
    public const REASON_SAME_ORGAN_SATURATION = 'same_organ_saturation';
    public const REASON_MISSING_IMPLEMENTATION = 'missing_implementation_pair';
    public const REASON_BATCH_TOO_SMALL = 'batch_too_small';
    public const REASON_BATCH_TOO_LARGE = 'batch_too_large';

    public const MIN_BATCH = 5;
    public const MAX_BATCH = 12;

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function evaluate(array $tasks): array
    {
        $count = count($tasks);

        if ($count < self::MIN_BATCH) {
            return $this->reject(self::REASON_BATCH_TOO_SMALL, $count);
        }
        if ($count > self::MAX_BATCH) {
            return $this->reject(self::REASON_BATCH_TOO_LARGE, $count);
        }

        $implCount = 0;
        $testCount = 0;
        $wrapperCount = 0;
        $organCounts = [];

        foreach ($tasks as $task) {
            $allowedFiles = (array) ($task['allowed_files'] ?? []);
            $hasImpl = false;
            $hasTest = false;
            $isWrapper = false;

            foreach ($allowedFiles as $file) {
                $file = (string) $file;
                if (str_starts_with($file, 'tests/')) {
                    $testCount++;
                    $hasTest = true;
                } elseif (str_starts_with($file, 'app/')) {
                    $implCount++;
                    $hasImpl = true;
                } elseif (str_contains($file, 'Wrapper') || str_contains($file, 'wrapper')) {
                    $wrapperCount++;
                    $isWrapper = true;
                }
            }

            if (! $hasImpl && ! $hasTest && $isWrapper) {
                // wrapper-only task
            }

            $organ = (string) ($task['organ'] ?? $task['target_organ'] ?? 'unknown');
            $organCounts[$organ] = ($organCounts[$organ] ?? 0) + 1;
        }

        // Test-only farm: all tasks have only test files
        if ($implCount === 0 && $testCount > 0) {
            return $this->reject(self::REASON_TEST_ONLY_FARM, $count);
        }

        // Wrapper-only farm: all tasks are wrappers
        if ($implCount === 0 && $testCount === 0 && $wrapperCount > 0) {
            return $this->reject(self::REASON_WRAPPER_ONLY_FARM, $count);
        }

        // Padding: no implementation files at all
        if ($implCount === 0) {
            return $this->reject(self::REASON_PADDING, $count);
        }

        // Same-organ saturation: all tasks target the same organ
        if (count($organCounts) === 1 && $count >= self::MIN_BATCH) {
            return $this->reject(self::REASON_SAME_ORGAN_SATURATION, $count);
        }

        // Missing implementation pair: any task without an app/ file
        foreach ($tasks as $task) {
            $allowedFiles = (array) ($task['allowed_files'] ?? []);
            $hasImpl = false;
            foreach ($allowedFiles as $file) {
                if (str_starts_with((string) $file, 'app/')) {
                    $hasImpl = true;
                    break;
                }
            }
            if (! $hasImpl) {
                return $this->reject(self::REASON_MISSING_IMPLEMENTATION, $count);
            }
        }

        return [
            'schema' => self::SCHEMA,
            'coherent' => true,
            'task_count' => $count,
            'rejection_reason' => null,
            'organ_diversity' => count($organCounts),
            'implementation_count' => $implCount,
            'test_count' => $testCount,
        ];
    }

    private function reject(string $reason, int $count): array
    {
        return [
            'schema' => self::SCHEMA,
            'coherent' => false,
            'task_count' => $count,
            'rejection_reason' => $reason,
            'organ_diversity' => 0,
            'implementation_count' => 0,
            'test_count' => 0,
        ];
    }
}
