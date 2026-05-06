<?php

namespace App\Services\Engineering;

final class EngineeringDockerHarnessInput
{
    public const DEFAULT_HEALTHCHECK_TIMEOUT_SECONDS = 45;

    public const MAX_HEALTHCHECK_TIMEOUT_SECONDS = 600;

    public const DEFAULT_ARTIFACT_MAX_FILES = 100;

    public const MAX_ARTIFACT_MAX_FILES = 1000;

    public const DEFAULT_ARTIFACT_MAX_BYTES = 10_485_760;

    public const MAX_ARTIFACT_MAX_BYTES = 524_288_000;

    public const DEFAULT_CACHE_RETENTION_DAYS = 14;

    public const MAX_CACHE_RETENTION_DAYS = 365;

    public const DEFAULT_ARTIFACT_RETENTION_DAYS = 30;

    public const MAX_ARTIFACT_RETENTION_DAYS = 365;

    public function healthcheckTimeoutSeconds(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.docker.healthcheck_timeout_seconds', self::DEFAULT_HEALTHCHECK_TIMEOUT_SECONDS),
            self::DEFAULT_HEALTHCHECK_TIMEOUT_SECONDS,
            1,
            self::MAX_HEALTHCHECK_TIMEOUT_SECONDS,
        );
    }

    public function artifactMaxFiles(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.docker.artifact_max_files', self::DEFAULT_ARTIFACT_MAX_FILES),
            self::DEFAULT_ARTIFACT_MAX_FILES,
            1,
            self::MAX_ARTIFACT_MAX_FILES,
        );
    }

    public function artifactMaxBytes(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.docker.artifact_max_bytes', self::DEFAULT_ARTIFACT_MAX_BYTES),
            self::DEFAULT_ARTIFACT_MAX_BYTES,
            1,
            self::MAX_ARTIFACT_MAX_BYTES,
        );
    }

    public function cacheRetentionDays(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.docker.cleanup.cache_retention_days', self::DEFAULT_CACHE_RETENTION_DAYS),
            self::DEFAULT_CACHE_RETENTION_DAYS,
            1,
            self::MAX_CACHE_RETENTION_DAYS,
        );
    }

    public function artifactRetentionDays(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.docker.cleanup.artifact_retention_days', self::DEFAULT_ARTIFACT_RETENTION_DAYS),
            self::DEFAULT_ARTIFACT_RETENTION_DAYS,
            1,
            self::MAX_ARTIFACT_RETENTION_DAYS,
        );
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
