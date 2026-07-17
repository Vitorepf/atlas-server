<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared ACOS measure-series freshness reader (ELEV-31 simplify / deepen reliability).
 *
 * Single source for "when was this registered series last updated?" used by:
 * - AcosDeadSeriesWatchdogCheck (TTL / dead series)
 * - AcosMaxWindowOrchestratorService (dead_window alerts)
 *
 * Supports table | command | jsonl | jsonl_dir — the orchestrator previously only
 * saw file-backed JSONL and missed command/table sources.
 */
final class AcosMeasureSeriesFreshnessReader
{
    public const SCHEMA = 'atlas.acos.measure_series_freshness_reader.v1';

    /**
     * @param  array<string,mixed>  $entry  registry entry
     */
    public function lastAppendAt(array $entry): ?CarbonImmutable
    {
        $sourceType = (string) ($entry['source_type'] ?? '');
        if (($entry['table'] ?? null) !== null || $sourceType === 'table') {
            return $this->tableLastAppendAt($entry);
        }
        if ($sourceType === 'command') {
            return $this->commandLastAppendAt($entry);
        }

        $path = (string) ($entry['path'] ?? '');
        if ($path === '') {
            return null;
        }

        if ($sourceType === 'jsonl_dir' || is_dir($path)) {
            return $this->jsonlDirLastAppendAt($path, $entry);
        }

        return $this->jsonlFileLastAppendAt($path, $entry);
    }

    /** @param array<string,mixed> $entry */
    private function commandLastAppendAt(array $entry): ?CarbonImmutable
    {
        $command = AiValueNormalizer::trimmedString($entry['path'] ?? '');
        if ($command === '' || ! str_starts_with($command, 'atlas:')) {
            return null;
        }

        try {
            Artisan::call($command);
            $decoded = json_decode(trim(Artisan::output()), true);
            if (! is_array($decoded)) {
                return null;
            }

            return $this->rowTimestamp(
                $this->flattenFirstPayload($decoded),
                (string) ($entry['timestamp_field'] ?? 'generated_at'),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $entry */
    private function tableLastAppendAt(array $entry): ?CarbonImmutable
    {
        $table = AiValueNormalizer::trimmedString($entry['table'] ?? '');
        $timestampField = AiValueNormalizer::trimmedString($entry['timestamp_field'] ?? 'occurred_at') ?: 'occurred_at';
        if ($table === '' || ! DatabaseTableAvailability::has($table)) {
            return null;
        }

        try {
            $query = DB::table($table);
            foreach ((array) ($entry['where'] ?? []) as $column => $value) {
                $query->where((string) $column, $value);
            }

            return $this->parseDate($query->max($timestampField));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $entry */
    private function jsonlDirLastAppendAt(string $dir, array $entry): ?CarbonImmutable
    {
        $latest = null;
        $files = is_dir($dir) ? glob(rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.jsonl') : [];
        foreach (AiValueNormalizer::arrayOrEmpty($files) as $file) {
            $candidate = $this->jsonlFileLastAppendAt((string) $file, $entry);
            if ($candidate instanceof CarbonImmutable && ($latest === null || $candidate->greaterThan($latest))) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /** @param array<string,mixed> $entry */
    private function jsonlFileLastAppendAt(string $path, array $entry): ?CarbonImmutable
    {
        if (! is_file($path)) {
            return null;
        }

        $latest = null;
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            try {
                while (($line = fgets($handle)) !== false) {
                    $decoded = json_decode(trim($line), true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $candidate = $this->rowTimestamp($decoded, (string) ($entry['timestamp_field'] ?? 'recorded_at'));
                    if ($candidate instanceof CarbonImmutable && ($latest === null || $candidate->greaterThan($latest))) {
                        $latest = $candidate;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        if ($latest instanceof CarbonImmutable) {
            return $latest;
        }

        // Empty / unparseable JSONL ⇒ missing (do NOT invent freshness from mtime —
        // that masked dead_window alerts when a series file was only touched).
        return null;
    }

    /** @param array<string,mixed> $row */
    private function rowTimestamp(array $row, string $preferredField): ?CarbonImmutable
    {
        foreach (array_values(array_unique([$preferredField, 'recorded_at', 'ts', 'created_at', 'occurred_at', 'timestamp'])) as $field) {
            $parsed = $this->parseDate($row[$field] ?? null);
            if ($parsed instanceof CarbonImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $decoded @return array<string,mixed> */
    private function flattenFirstPayload(array $decoded): array
    {
        foreach ($decoded as $value) {
            if (is_array($value)) {
                return $value;
            }
        }

        return $decoded;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
