<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Optional string option reader that preserves empty strings (no trim).
 *
 * Full-pass sibling of {@see ReadsNonEmptyStringOption} for CLIs that treat
 * "" as a valid explicit value rather than "absent".
 */
trait ReadsRawStringOption
{
    /** Any string, including empty. Null only when the option is not a string. */
    private function rawStringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }
}
