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
    public const FIELD_TIMESTAMP_FIELD = 'timestamp_field';
    public const FIELD_TABLE = 'table';
    public const FIELD_PATH = 'path';
    public const FIELD_WHERE = 'where';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_COMMAND = 'command';
    public const FIELD_JSONL_DIR = 'jsonl_dir';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_OCCURRED_AT = 'occurred_at';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_RB = 'rb';
    public const FIELD_TIMESTAMP = 'timestamp';
    public const FIELD_TS = 'ts';

    /**
     * @param  array<string,mixed>  $entry  registry entry
     */
    public function lastAppendAt(array $entry): ?CarbonImmutable
    {
        $sourceType = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SOURCE_TYPE] ?? null) ?? '';
        if (($entry[self::FIELD_TABLE] ?? null) !== null || $sourceType === self::FIELD_TABLE) {
            return $this->tableLastAppendAt($entry);
        }
        if ($sourceType === self::FIELD_COMMAND) {
            return $this->commandLastAppendAt($entry);
        }

        $path = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_PATH] ?? null) ?? '';
        if ($path === '') {
            return null;
        }

        if ($sourceType === self::FIELD_JSONL_DIR || is_dir($path)) {
            return $this->jsonlDirLastAppendAt($path, $entry);
        }

        return $this->jsonlFileLastAppendAt($path, $entry);
    }

    /** @param array<string,mixed> $entry */
    private function commandLastAppendAt(array $entry): ?CarbonImmutable
    {
        $command = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_PATH] ?? null) ?? '';
        if ($command === '' || ! str_starts_with($command, 'atlas:')) {
            return null;
        }

        try {
            Artisan::call($command);
            $decoded = json_decode(AiValueNormalizer::trimmedStringOrNull(Artisan::output()) ?? '', true);
            if (! is_array($decoded)) {
                return null;
            }

            return $this->rowTimestamp(
                $this->flattenFirstPayload($decoded),
                AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TIMESTAMP_FIELD] ?? null) ?? self::FIELD_GENERATED_AT,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $entry */
    private function tableLastAppendAt(array $entry): ?CarbonImmutable
    {
        $table = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TABLE] ?? null) ?? '';
        $timestampField = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TIMESTAMP_FIELD] ?? null) ?? self::FIELD_OCCURRED_AT;
        if ($table === '' || ! DatabaseTableAvailability::has($table)) {
            return null;
        }

        try {
            $query = DB::table($table);
            foreach (AiValueNormalizer::arrayOrEmpty($entry[self::FIELD_WHERE] ?? null) as $column => $value) {
                $query->where(AiValueNormalizer::trimmedStringOrNull($column) ?? '', $value);
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
            $candidate = $this->jsonlFileLastAppendAt(AiValueNormalizer::trimmedStringOrNull($file) ?? '', $entry);
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
        $handle = fopen($path, self::FIELD_RB);
        if ($handle !== false) {
            try {
                while (($line = fgets($handle)) !== false) {
                    $decoded = json_decode(AiValueNormalizer::trimmedStringOrNull($line) ?? '', true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $candidate = $this->rowTimestamp(
                        $decoded,
                        AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TIMESTAMP_FIELD] ?? null) ?? self::FIELD_RECORDED_AT,
                    );
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
        foreach (array_values(array_unique([$preferredField, self::FIELD_RECORDED_AT, self::FIELD_TS, self::FIELD_CREATED_AT, self::FIELD_OCCURRED_AT, self::FIELD_TIMESTAMP])) as $field) {
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
