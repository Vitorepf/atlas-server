<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Shared non-empty string option reader for Atlas CLI commands.
 */
trait ReadsNonEmptyStringOption
{
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
