<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure circuit breaker that stops a round before enqueue when proposed targets
 * already appear in queued-targets or within the same batch.
 *
 * Trips on:
 *   - live target duplicates (proposed target already in queued targets)
 *   - intra-batch duplicates (same target appears twice in the proposed batch)
 *   - implementation/test swapped duplicates (target A's test file matches target B's impl)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainDuplicateTargetCircuitBreaker
{
    public const SCHEMA = 'atlas.external_brain.duplicate_target_circuit_breaker.v1';

    public const TRIP_LIVE_DUPLICATE = 'live_target_duplicate';
    public const TRIP_INTRA_BATCH_DUPLICATE = 'intra_batch_duplicate';
    public const TRIP_IMPL_TEST_SWAPPED = 'implementation_test_swapped_duplicate';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function check(array $input): array
    {
        $proposedTargets = (array) ($input['proposed_targets'] ?? []);
        $queuedTargets = (array) ($input['queued_targets'] ?? []);

        $trips = [];

        // Check for live target duplicates (proposed target already in queued targets).
        $queuedSet = array_flip(array_map('strval', $queuedTargets));
        $liveDuplicates = [];
        foreach ($proposedTargets as $proposed) {
            $target = is_array($proposed) ? (string) ($proposed['target_family'] ?? '') : (string) $proposed;
            if ($target !== '' && isset($queuedSet[$target])) {
                $liveDuplicates[] = $target;
            }
        }
        if ($liveDuplicates !== []) {
            $trips[] = [
                'type' => self::TRIP_LIVE_DUPLICATE,
                'targets' => array_values(array_unique($liveDuplicates)),
            ];
        }

        // Check for intra-batch duplicates (same target appears twice in proposed batch).
        $seen = [];
        $intraBatchDuplicates = [];
        foreach ($proposedTargets as $proposed) {
            $target = is_array($proposed) ? (string) ($proposed['target_family'] ?? '') : (string) $proposed;
            if ($target === '') {
                continue;
            }
            if (isset($seen[$target])) {
                $intraBatchDuplicates[] = $target;
            }
            $seen[$target] = true;
        }
        if ($intraBatchDuplicates !== []) {
            $trips[] = [
                'type' => self::TRIP_INTRA_BATCH_DUPLICATE,
                'targets' => array_values(array_unique($intraBatchDuplicates)),
            ];
        }

        // Check for implementation/test swapped duplicates.
        // If target A's allowed_files include a test file that matches target B's impl file path pattern.
        $implTestSwapped = [];
        $proposedArrays = array_filter($proposedTargets, 'is_array');
        foreach ($proposedArrays as $i => $a) {
            $aFiles = (array) ($a['allowed_files'] ?? []);
            $aTarget = (string) ($a['target_family'] ?? '');
            foreach ($proposedArrays as $j => $b) {
                if ($i >= $j) {
                    continue;
                }
                $bFiles = (array) ($b['allowed_files'] ?? []);
                $bTarget = (string) ($b['target_family'] ?? '');
                if ($aTarget === $bTarget || $aTarget === '' || $bTarget === '') {
                    continue;
                }
                // Check if A's test file matches B's impl file (swapped).
                foreach ($aFiles as $aFile) {
                    $aFile = (string) $aFile;
                    if (! $this->isTestFile($aFile)) {
                        continue;
                    }
                    $aImplEquivalent = $this->testToImplPath($aFile);
                    foreach ($bFiles as $bFile) {
                        $bFile = (string) $bFile;
                        if ($bFile === $aImplEquivalent) {
                            $implTestSwapped[] = $aTarget.'::'.$bTarget;
                        }
                    }
                }
            }
        }
        if ($implTestSwapped !== []) {
            $trips[] = [
                'type' => self::TRIP_IMPL_TEST_SWAPPED,
                'targets' => array_values(array_unique($implTestSwapped)),
            ];
        }

        $tripped = $trips !== [];

        return [
            'schema_version' => self::SCHEMA,
            'tripped' => $tripped,
            'trips' => $trips,
            'proposed_target_count' => count($proposedTargets),
            'queued_target_count' => count($queuedTargets),
            'stop_before_enqueue' => $tripped,
        ];
    }

    private function isTestFile(string $path): bool
    {
        $lower = strtolower($path);

        return str_contains($lower, '/tests/') || str_ends_with($lower, 'test.php');
    }

    private function testToImplPath(string $testPath): string
    {
        // tests/Unit/Foo/FooTest.php → app/Services/Foo/Foo.php (best-effort heuristic).
        $lower = strtolower($testPath);
        if (str_starts_with($lower, 'tests/unit/')) {
            $basename = basename($testPath);
            // Remove "Test.php" suffix.
            if (str_ends_with($basename, 'Test.php')) {
                $classBase = substr($basename, 0, -8);

                return 'app/Services/'.$classBase.'.php';
            }
        }

        return '';
    }
}
