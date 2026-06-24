<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal;

use Composer\Autoload\ClassLoader;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Process\Process;

final class AtlasCortexOrphanAgeReporter
{
    /**
     * @param  null|Closure():string  $headTimestampResolver
     */
    public function __construct(
        private readonly ?string $repoRoot = null,
        private readonly ?Closure $headTimestampResolver = null,
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

        $unwiredSinceAt = $this->firstSeenAt($path);
        $headTimestamp = $this->headTimestamp();

        return [
            'fqcn' => $fqcn,
            'resolved' => true,
            'file_path' => $path,
            'unwired_since_at' => $unwiredSinceAt,
            'unwired_days' => $this->unwiredDays($unwiredSinceAt, $headTimestamp),
            'last_modified_at' => $this->gitTimestamp(['log', '-1', '--format=%cI', '--', $this->relativePath($path)]),
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

    private function headTimestamp(): ?string
    {
        if (is_callable($this->headTimestampResolver)) {
            $value = ($this->headTimestampResolver)();

            return $value !== '' ? $this->normalizeTimestamp($value) : null;
        }

        return $this->gitTimestamp(['log', '-1', '--format=%cI', 'HEAD']);
    }

    private function unwiredDays(?string $unwiredSinceAt, ?string $headTimestamp): ?int
    {
        if ($unwiredSinceAt === null || $headTimestamp === null) {
            return null;
        }

        $start = new DateTimeImmutable($unwiredSinceAt, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($headTimestamp, new DateTimeZone('UTC'));
        $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());

        return (int) floor($seconds / 86400);
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
            ->setTimezone(new DateTimeZone('UTC'))
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
