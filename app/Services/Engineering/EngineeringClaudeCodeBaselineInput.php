<?php

namespace App\Services\Engineering;

final class EngineeringClaudeCodeBaselineInput
{
    public const DEFAULT_TIMEOUT_SECONDS = 900;

    public const MAX_TIMEOUT_SECONDS = 3600;

    public const DEFAULT_VALIDATION_TIMEOUT_SECONDS = 300;

    public const MAX_VALIDATION_TIMEOUT_SECONDS = 1800;

    /**
     * @param  array<string,mixed>  $options
     */
    public function runTimeoutSeconds(array $options): int
    {
        return $this->limit(
            $options['claude_code_baseline_timeout'] ?? $options['baseline_timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS,
            self::DEFAULT_TIMEOUT_SECONDS,
            1,
            self::MAX_TIMEOUT_SECONDS,
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function validationTimeoutSeconds(array $options): int
    {
        return $this->limit(
            $options['claude_code_baseline_validation_timeout'] ?? $options['baseline_validation_timeout_seconds'] ?? self::DEFAULT_VALIDATION_TIMEOUT_SECONDS,
            self::DEFAULT_VALIDATION_TIMEOUT_SECONDS,
            1,
            self::MAX_VALIDATION_TIMEOUT_SECONDS,
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
