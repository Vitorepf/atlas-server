<?php

namespace App\Services\Engineering;

final class EngineeringTestMatrixInput
{
    public const DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS = 300;

    public const MAX_QUALITY_SCAN_TIMEOUT_SECONDS = 3600;

    public const DEFAULT_VISUAL_SMOKE_TIMEOUT_SECONDS = 45;

    public const MAX_VISUAL_SMOKE_TIMEOUT_SECONDS = 1800;

    public const DEFAULT_VISUAL_ARTIFACT_MAX_FILES = 200;

    public const MAX_VISUAL_ARTIFACT_MAX_FILES = 2000;

    public const DEFAULT_VISUAL_ARTIFACT_MAX_BYTES = 52_428_800;

    public const MAX_VISUAL_ARTIFACT_MAX_BYTES = 1_073_741_824;

    public const DEFAULT_QUALITY_ARTIFACT_MAX_FILES = 100;

    public const MAX_QUALITY_ARTIFACT_MAX_FILES = 1000;

    public const DEFAULT_QUALITY_ARTIFACT_MAX_BYTES = 10_485_760;

    public const MAX_QUALITY_ARTIFACT_MAX_BYTES = 524_288_000;

    public function qualityScanTimeoutSeconds(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.quality_scan.timeout_seconds', self::DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS),
            self::DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS,
            10,
            self::MAX_QUALITY_SCAN_TIMEOUT_SECONDS,
        );
    }

    public function visualSmokeTimeoutSeconds(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.visual_e2e.managed_smoke_timeout_seconds', self::DEFAULT_VISUAL_SMOKE_TIMEOUT_SECONDS),
            self::DEFAULT_VISUAL_SMOKE_TIMEOUT_SECONDS,
            5,
            self::MAX_VISUAL_SMOKE_TIMEOUT_SECONDS,
        );
    }

    public function visualArtifactMaxFiles(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.visual_e2e.artifact_max_files', self::DEFAULT_VISUAL_ARTIFACT_MAX_FILES),
            self::DEFAULT_VISUAL_ARTIFACT_MAX_FILES,
            1,
            self::MAX_VISUAL_ARTIFACT_MAX_FILES,
        );
    }

    public function visualArtifactMaxBytes(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.visual_e2e.artifact_max_bytes', self::DEFAULT_VISUAL_ARTIFACT_MAX_BYTES),
            self::DEFAULT_VISUAL_ARTIFACT_MAX_BYTES,
            1,
            self::MAX_VISUAL_ARTIFACT_MAX_BYTES,
        );
    }

    public function qualityArtifactMaxFiles(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.quality_scan.artifact_max_files', self::DEFAULT_QUALITY_ARTIFACT_MAX_FILES),
            self::DEFAULT_QUALITY_ARTIFACT_MAX_FILES,
            1,
            self::MAX_QUALITY_ARTIFACT_MAX_FILES,
        );
    }

    public function qualityArtifactMaxBytes(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.engineering.quality_scan.artifact_max_bytes', self::DEFAULT_QUALITY_ARTIFACT_MAX_BYTES),
            self::DEFAULT_QUALITY_ARTIFACT_MAX_BYTES,
            1,
            self::MAX_QUALITY_ARTIFACT_MAX_BYTES,
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
