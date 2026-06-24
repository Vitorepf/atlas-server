<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use Symfony\Component\Process\Process;

final class AtlasCortexSymbolAgeReporter
{
    public function __construct(
        private readonly ?string $repoRoot = null,
    ) {}

    /**
     * @param  list<string>  $fqcns
     * @return array<string,array<string,mixed>>
     */
    public function report(array $fqcns): array
    {
        $sorted = array_values(array_unique(array_filter($fqcns, static fn (mixed $fqcn): bool => is_string($fqcn) && $fqcn !== '')));
        sort($sorted, SORT_STRING);

        $rows = [];
        foreach ($sorted as $fqcn) {
            $rows[$fqcn] = $this->reportOne($fqcn);
        }

        ksort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function reportOne(string $fqcn): array
    {
        $path = $this->resolvePath($fqcn);
        if ($path === null) {
            return [
                'fqcn' => $fqcn,
                'resolved' => false,
                'reason' => 'fqcn_not_resolved_via_composer_autoload',
            ];
        }

        return [
            'fqcn' => $fqcn,
            'resolved' => true,
            'file_path' => $path,
            'last_modified_at' => $this->gitTimestamp(['log', '-1', '--format=%cI', '--', $this->relativePath($path)]),
            'last_tested_at' => $this->lastTestedAt($fqcn),
            'first_seen_at' => $this->firstSeenAt($path),
            'modification_count_30d' => $this->modificationCount30d($path),
        ];
    }

    private function resolvePath(string $fqcn): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $path = $loader->findFile($fqcn);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    private function lastTestedAt(string $fqcn): ?string
    {
        $timestamps = [];
        foreach ($this->testFilesReferencing($fqcn) as $path) {
            $relative = $this->relativePath($path);
            $timestamp = $this->gitTimestamp(['log', '-1', '--format=%cI', '--', $relative]);
            if ($timestamp !== null) {
                $timestamps[] = $timestamp;
            }
        }

        if ($timestamps === []) {
            return null;
        }

        sort($timestamps, SORT_STRING);

        return $timestamps[array_key_last($timestamps)];
    }

    private function firstSeenAt(string $path): ?string
    {
        $output = $this->gitOutput(['log', '--diff-filter=A', '--follow', '--format=%cI', '--', $this->relativePath($path)]);
        if ($output === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));
        if ($lines === []) {
            return null;
        }

        return $this->normalizeTimestamp($lines[array_key_last($lines)]);
    }

    private function modificationCount30d(string $path): int
    {
        $output = $this->gitOutput(['log', '--since=30 days ago', '--format=%H', '--', $this->relativePath($path)]);
        if ($output === null) {
            return 0;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));

        return count($lines);
    }

    /**
     * @return list<string>
     */
    private function testFilesReferencing(string $fqcn): array
    {
        $root = $this->repoRoot().DIRECTORY_SEPARATOR.'tests';
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $contents = @file_get_contents($path);
            if (! is_string($contents) || ! str_contains($contents, $fqcn)) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function gitTimestamp(array $args): ?string
    {
        $output = $this->gitOutput($args);
        if ($output === null) {
            return null;
        }

        $timestamp = trim($output);
        if ($timestamp === '') {
            return null;
        }

        return $this->normalizeTimestamp($timestamp);
    }

    private function normalizeTimestamp(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->repoRoot(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }

    private function repoRoot(): string
    {
        return $this->repoRoot ?? base_path();
    }

    private function gitOutput(array $args): ?string
    {
        $process = new Process(array_merge(['git'], $args), $this->repoRoot());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }
}
