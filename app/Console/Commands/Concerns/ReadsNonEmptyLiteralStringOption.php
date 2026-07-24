<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Optional string option: non-empty literal without trimming (spaces-only is non-empty).
 */
trait ReadsNonEmptyLiteralStringOption
{
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
