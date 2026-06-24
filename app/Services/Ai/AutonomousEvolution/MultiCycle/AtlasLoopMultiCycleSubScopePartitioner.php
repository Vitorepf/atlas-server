<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\MultiCycle;

use InvalidArgumentException;

final class AtlasLoopMultiCycleSubScopePartitioner
{
    /**
     * @param  list<string>  $forbiddenGlobs
     */
    public function __construct(
        private readonly array $forbiddenGlobs = [],
    ) {
    }

    /**
     * @param  list<string>  $scopeFiles
     * @return list<array{cycle_id:string,files:list<string>}>
     */
    public function partition(array $scopeFiles, int $cycleCount, string $salt): array
    {
        if ($cycleCount < 1) {
            throw new InvalidArgumentException('cycleCount must be >= 1.');
        }

        $normalized = $this->normalizedScopeFiles($scopeFiles);
        $this->assertNoForbiddenPath($normalized);

        $ranked = array_map(
            fn (string $path): array => [
                'path' => $path,
                'rank' => sha1($salt.'|'.$path),
            ],
            $normalized,
        );

        usort(
            $ranked,
            static fn (array $left, array $right): int => [$left['rank'], $left['path']] <=> [$right['rank'], $right['path']],
        );

        $partitions = [];
        for ($index = 0; $index < $cycleCount; $index++) {
            $partitions[$index] = [
                'cycle_id' => 'cycle-'.($index + 1),
                'files' => [],
            ];
        }

        foreach ($ranked as $offset => $row) {
            $partitions[$offset % $cycleCount]['files'][] = $row['path'];
        }

        $seen = [];
        foreach ($partitions as &$partition) {
            sort($partition['files'], SORT_STRING);
            foreach ($partition['files'] as $path) {
                if (isset($seen[$path])) {
                    throw new InvalidArgumentException('Overlapping sub-scope emitted for path: '.$path);
                }
                $seen[$path] = true;
            }
        }
        unset($partition);

        return array_values($partitions);
    }

    /**
     * @param  list<string>  $scopeFiles
     * @return list<string>
     */
    private function normalizedScopeFiles(array $scopeFiles): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $path): string => $this->normalizePath((string) $path),
            $scopeFiles,
        ), static fn (string $path): bool => $path !== '')));

        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return ltrim($path, '/');
    }

    /**
     * @param  list<string>  $scopeFiles
     */
    private function assertNoForbiddenPath(array $scopeFiles): void
    {
        foreach ($scopeFiles as $path) {
            foreach ($this->forbiddenGlobs as $glob) {
                if (fnmatch($glob, $path)) {
                    throw new InvalidArgumentException('Forbidden path in scope: '.$path);
                }
            }
        }
    }
}
