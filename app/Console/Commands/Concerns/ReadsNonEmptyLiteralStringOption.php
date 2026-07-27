<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Optional string option: non-empty literal without trimming (spaces-only is non-empty).
 */
trait ReadsNonEmptyLiteralStringOption
{
    /** Non-empty WITHOUT trimming: a value of ' ' is returned as-is. */
    private function literalStringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
