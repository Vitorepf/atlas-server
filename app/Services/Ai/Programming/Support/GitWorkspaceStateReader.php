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
    /**
     * @param  int  $maxDirtySample  Max dirty paths in dirty_files_sample; 0 = unlimited
     * @return array{
     *   is_git: bool,
     *   clean: bool,
     *   status: string,
     *   dirty_count: int,
     *   dirty_files_sample: list<string>,
     *   dirty_files_truncated?: bool
     * }
     */
    public static function read(string $workspace, int $maxDirtySample = 20): array
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

        $count = $dirtyFiles->count();
        $sample = $maxDirtySample <= 0
            ? $dirtyFiles->all()
            : $dirtyFiles->take($maxDirtySample)->all();

        return [
            'is_git' => true,
            'clean' => $count === 0,
            'status' => $count === 0 ? 'clean' : 'dirty',
            'dirty_count' => $count,
            'dirty_files_sample' => $sample,
            'dirty_files_truncated' => $maxDirtySample > 0 && $count > $maxDirtySample,
        ];
    }

    /**
     * Benchmark fair CLI/controller shape (legacy keys).
     *
     * @return array{is_git: bool, clean: bool|null, dirty_files: list<string>, status: string}
     */
    public static function readBenchmarkShape(string $workspace): array
    {
        $state = self::read($workspace, 0);
        if (! $state['is_git']) {
            return [
                'is_git' => false,
                'clean' => null,
                'dirty_files' => [],
                'status' => $state['status'] === 'workspace_missing' ? 'not_git_workspace' : $state['status'],
            ];
        }

        return [
            'is_git' => true,
            'clean' => $state['clean'],
            'dirty_files' => $state['dirty_files_sample'],
            'status' => $state['status'],
        ];
    }
}
