<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class AtlasLoopCancelledTaskMiner
{
    public function __construct(
        private readonly object|array|null $reader = null,
        private readonly mixed $now = null,
    ) {}

    /**
     * @return list<array{
     *   cluster_id:string,
     *   member_count:int,
     *   member_task_ids:list<string>,
     *   reason:string,
     *   reason_class:string,
     *   shared_gate:string,
     *   shared_prefix:string
     * }>
     */
    public function mine(int $limit = 200): array
    {
        $buckets = [];

        foreach ($this->relevantRecords($limit) as $record) {
            $reason = $this->reason($record);
            $gate = $this->gate($record);
            $bucketKey = $reason.'|'.$gate;
            $buckets[$bucketKey][] = $record;
        }

        $patterns = [];
        foreach ($buckets as $records) {
            if (count($records) < 3) {
                continue;
            }

            $sharedPrefix = $this->sharedPrefix($records);
            if ($sharedPrefix === '') {
                continue;
            }

            $taskIds = [];
            foreach ($records as $record) {
                $taskIds[] = (string) $record['task_packet_id'];
            }
            sort($taskIds);

            $reason = $this->reason($records[0]);
            $gate = $this->gate($records[0]);
            $clusterId = hash('sha256', json_encode([
                'gate' => $gate,
                'reason' => $reason,
                'shared_prefix' => $sharedPrefix,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $patterns[] = [
                'cluster_id' => $clusterId,
                'member_count' => count($taskIds),
                'member_task_ids' => $taskIds,
                'reason' => $reason,
                'reason_class' => $this->reasonClass($reason),
                'shared_gate' => $gate,
                'shared_prefix' => $sharedPrefix,
            ];
        }

        usort($patterns, static fn (array $left, array $right): int => strcmp($left['cluster_id'], $right['cluster_id']));

        return $patterns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relevantRecords(int $limit): array
    {
        $records = $this->records($limit);
        $cutoff = $this->now()->sub(new DateInterval('P'.$this->windowDays().'D'));
        $relevant = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $taskPacketId = trim((string) ($record['task_packet_id'] ?? ''));
            $reason = $this->reason($record);
            $allowedFiles = $this->allowedFiles($record);
            $recordedAt = $this->recordedAt($record);

            if ($taskPacketId === '' || $reason === '' || $allowedFiles === [] || $recordedAt === null) {
                continue;
            }

            if ($recordedAt < $cutoff) {
                continue;
            }

            $relevant[] = $record;
        }

        return $relevant;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(int $limit): array
    {
        if (is_array($this->reader)) {
            return array_values($this->reader);
        }

        if (is_object($this->reader)) {
            foreach (['recentRecords', 'records', 'recent', 'read'] as $method) {
                if (! method_exists($this->reader, $method)) {
                    continue;
                }

                $result = $this->reader->{$method}($limit);

                return is_array($result) ? array_values($result) : [];
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function sharedPrefix(array $records): string
    {
        $candidates = [];

        foreach ($records as $record) {
            $allowedFiles = $this->allowedFiles($record);
            $candidate = $this->pathStemPrefix($allowedFiles);
            if ($candidate === '') {
                return '';
            }

            $candidates[] = $candidate;
        }

        $shared = array_shift($candidates) ?? '';
        foreach ($candidates as $candidate) {
            $shared = $this->commonPrefix($shared, $candidate);
            if ($shared === '') {
                return '';
            }
        }

        $lastSlash = strrpos($shared, '/');
        $stem = $lastSlash === false ? $shared : substr($shared, $lastSlash + 1);
        if ($stem === '' || strlen($stem) < 3) {
            return '';
        }

        return rtrim($shared, '/');
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function pathStemPrefix(array $allowedFiles): string
    {
        sort($allowedFiles);
        $path = $allowedFiles[0] ?? '';
        if ($path === '') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($path));
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $prefix = preg_replace('/(?:[A-Z][a-z0-9]+)$/', '', $basename);
        if (! is_string($prefix) || $prefix === '') {
            $prefix = $basename;
        }

        return trim($directory.'/'.$prefix, '/');
    }

    private function commonPrefix(string $left, string $right): string
    {
        $max = min(strlen($left), strlen($right));
        $prefix = '';

        for ($index = 0; $index < $max; $index++) {
            if ($left[$index] !== $right[$index]) {
                break;
            }

            $prefix .= $left[$index];
        }

        return rtrim($prefix, '/');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function allowedFiles(array $record): array
    {
        $allowedFiles = array_values(array_filter(
            is_array($record['allowed_files'] ?? null) ? $record['allowed_files'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        sort($allowedFiles);

        return $allowedFiles;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function gate(array $record): string
    {
        $gate = trim((string) ($record['gate'] ?? ''));
        if ($gate !== '') {
            return $gate;
        }

        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $gate = trim((string) ($metadata['gate'] ?? ''));
        if ($gate !== '') {
            return $gate;
        }

        $blocking = is_array($metadata['blocking_deficiencies'] ?? null) ? $metadata['blocking_deficiencies'] : [];

        return trim((string) ($blocking[0] ?? 'none'));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function reason(array $record): string
    {
        $reason = trim((string) ($record['reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];

        return trim((string) ($metadata['reason'] ?? ''));
    }

    private function reasonClass(string $reason): string
    {
        return str_contains($reason, 'give_back') ? 'repeated_give_back' : $reason;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordedAt(array $record): ?DateTimeImmutable
    {
        foreach (['cancelled_at', 'recorded_at', 'updated_at', 'created_at'] as $key) {
            $value = trim((string) ($record[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function now(): DateTimeImmutable
    {
        $value = $this->now !== null ? ($this->now)() : 'now';

        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable(is_string($value) ? $value : 'now');
    }

    private function windowDays(): int
    {
        $days = (int) config('atlas.loop.trinity.maestro_to_loop.avoid_pattern_window_days', 30);

        return $days > 0 ? $days : 30;
    }
}
