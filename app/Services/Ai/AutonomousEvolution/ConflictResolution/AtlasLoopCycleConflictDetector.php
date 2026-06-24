<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ConflictResolution;

use App\Services\Ai\SelfConstruction\WriteSetOverlap;
use Closure;

final class AtlasLoopCycleConflictDetector
{
    /**
     * @param  null|Closure(list<string>,list<string>):list<string>  $overlapPredicate
     */
    public function __construct(
        private readonly ?Closure $overlapPredicate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    public function detect(array $left, array $right): AtlasLoopCycleConflictReport
    {
        $leftFiles = $this->filesByPath((array) ($left['files'] ?? []));
        $rightFiles = $this->filesByPath((array) ($right['files'] ?? []));

        $overlappingPaths = $this->collidingPaths(array_keys($leftFiles), array_keys($rightFiles));
        $perFile = [];
        foreach ($overlappingPaths as $path) {
            $leftFile = $leftFiles[$path] ?? [];
            $rightFile = $rightFiles[$path] ?? [];
            $perFile[] = [
                'path' => $path,
                'mode' => $this->modeFor($leftFile, $rightFile),
            ];
        }

        usort($perFile, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return new AtlasLoopCycleConflictReport(
            (string) ($left['cycle_id'] ?? ''),
            (string) ($right['cycle_id'] ?? ''),
            $overlappingPaths,
            $perFile,
        );
    }

    /**
     * @param  list<string>  $leftPaths
     * @param  list<string>  $rightPaths
     * @return list<string>
     */
    private function collidingPaths(array $leftPaths, array $rightPaths): array
    {
        if (is_callable($this->overlapPredicate)) {
            $hits = ($this->overlapPredicate)($leftPaths, $rightPaths);

            return is_array($hits) ? array_values(array_map('strval', $hits)) : [];
        }

        return WriteSetOverlap::collidingPaths($leftPaths, $rightPaths);
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @return array<string,array<string,mixed>>
     */
    private function filesByPath(array $files): array
    {
        $map = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = trim((string) ($file['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $map[$path] = $file;
        }

        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function modeFor(array $left, array $right): string
    {
        if ((string) ($left['content_hash'] ?? '') === (string) ($right['content_hash'] ?? '')) {
            return 'identical-bytes';
        }

        if ($this->rangesOverlap((array) ($left['byte_range'] ?? []), (array) ($right['byte_range'] ?? []))) {
            return 'divergent-bytes';
        }

        return 'disjoint-hunks';
    }

    /**
     * @param  array<int,int|string>  $left
     * @param  array<int,int|string>  $right
     */
    private function rangesOverlap(array $left, array $right): bool
    {
        $leftStart = (int) ($left[0] ?? 0);
        $leftEnd = (int) ($left[1] ?? 0);
        $rightStart = (int) ($right[0] ?? 0);
        $rightEnd = (int) ($right[1] ?? 0);

        return max($leftStart, $rightStart) <= min($leftEnd, $rightEnd);
    }
}
