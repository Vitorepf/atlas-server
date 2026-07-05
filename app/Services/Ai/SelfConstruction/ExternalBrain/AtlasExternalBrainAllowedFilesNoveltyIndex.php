<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects duplicate or near-duplicate allowed_files sets across
 * proposed and live autonomous tasks before the originator spends a seed.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainAllowedFilesNoveltyIndex
{
    public const SCHEMA = 'atlas.self_construction.external_brain_allowed_files_novelty_index.v1';

    public const FLAG_EXACT_DUPLICATE = 'exact_duplicate';
    public const FLAG_SAME_IMPL_DIFFERENT_TEST = 'same_implementation_different_test';
    public const FLAG_SAME_TEST_DIFFERENT_WRAPPER = 'same_test_different_wrapper';

    /**
     * @param  array<string>  $proposedFiles
     * @param  array<array<string, mixed>>  $liveTasks  [{task_id: string, allowed_files: list<string>}]
     * @return array<string, mixed>
     */
    public function evaluate(array $proposedFiles, array $liveTasks = []): array
    {
        $proposed = $this->normalize($proposedFiles);
        $proposedImpl = $this->filterImpl($proposed);
        $proposedTests = $this->filterTests($proposed);

        $flags = [];
        $duplicates = [];

        foreach ($liveTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $taskId = (string) ($task['task_id'] ?? '');
            $liveFiles = $this->normalize((array) ($task['allowed_files'] ?? []));

            // Exact duplicate
            if ($proposed === $liveFiles) {
                $flags[] = self::FLAG_EXACT_DUPLICATE;
                $duplicates[] = ['task_id' => $taskId, 'flag' => self::FLAG_EXACT_DUPLICATE];
                continue;
            }

            $liveImpl = $this->filterImpl($liveFiles);
            $liveTests = $this->filterTests($liveFiles);

            // Same implementation, different test
            if ($proposedImpl === $liveImpl && $proposedImpl !== [] && $proposedTests !== $liveTests) {
                $flags[] = self::FLAG_SAME_IMPL_DIFFERENT_TEST;
                $duplicates[] = ['task_id' => $taskId, 'flag' => self::FLAG_SAME_IMPL_DIFFERENT_TEST];
                continue;
            }

            // Same test, different wrapper
            if ($proposedTests === $liveTests && $proposedTests !== [] && $proposedImpl !== $liveImpl) {
                $flags[] = self::FLAG_SAME_TEST_DIFFERENT_WRAPPER;
                $duplicates[] = ['task_id' => $taskId, 'flag' => self::FLAG_SAME_TEST_DIFFERENT_WRAPPER];
                continue;
            }
        }

        $flags = array_values(array_unique($flags));

        return [
            'schema' => self::SCHEMA,
            'novel' => $flags === [],
            'flags' => $flags,
            'duplicates' => $duplicates,
            'proposed_file_count' => count($proposed),
            'proposed_implementation_files' => $proposedImpl,
            'proposed_test_files' => $proposedTests,
        ];
    }

    /**
     * @param  array<string>  $files
     * @return array<string>
     */
    private function normalize(array $files): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $files))));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<string>  $files
     * @return array<string>
     */
    private function filterImpl(array $files): array
    {
        return array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'app/')));
    }

    /**
     * @param  array<string>  $files
     * @return array<string>
     */
    private function filterTests(array $files): array
    {
        return array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'tests/')));
    }
}
