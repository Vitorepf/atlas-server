<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use Symfony\Component\Process\Process;

/**
 * Shared git workspace cleanliness probe (full-pass reuse).
 *
 * Extracted from byte-identical private gitWorkspaceState() copies in
 * ProgrammingRivalsReadinessService and AtlasForgeNativeRivalsPreflightService.
 *
 * @return array{
 *   is_git: bool,
 *   clean: bool,
 *   status: string,
 *   dirty_count: int,
 *   dirty_files_sample: list<string>,
 *   dirty_files_truncated?: bool
 * }
 */
final class GitWorkspaceStateReader
{
    public static function read(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [
                'is_git' => false,
                'clean' => false,
                'status' => 'workspace_missing',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $inside->setTimeout(5);
        $inside->run();

        if (! $inside->isSuccessful() || trim($inside->getOutput()) !== 'true') {
            return [
                'is_git' => false,
                'clean' => false,
                'status' => 'not_git_workspace',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $status = new Process(['git', 'status', '--porcelain'], $workspace);
        $status->setTimeout(10);
        $status->run();

        if (! $status->isSuccessful()) {
            return [
                'is_git' => true,
                'clean' => false,
                'status' => 'git_status_unavailable',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $dirtyFiles = collect(explode("\n", trim($status->getOutput())))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values();

        return [
            'is_git' => true,
            'clean' => $dirtyFiles->isEmpty(),
            'status' => $dirtyFiles->isEmpty() ? 'clean' : 'dirty',
            'dirty_count' => $dirtyFiles->count(),
            'dirty_files_sample' => $dirtyFiles->take(20)->all(),
            'dirty_files_truncated' => $dirtyFiles->count() > 20,
        ];
    }
}
