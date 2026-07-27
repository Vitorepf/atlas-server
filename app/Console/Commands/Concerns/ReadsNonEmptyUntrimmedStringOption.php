<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Optional string option reader: reject blank-after-trim, but return the original
 * untrimmed value when non-blank (preserves intentional leading/trailing spaces).
 *
 * Full-pass sibling of {@see ReadsNonEmptyStringOption} for Programming/Sdd CLIs.
 */
trait ReadsNonEmptyUntrimmedStringOption
{
    /** Null when the value trims to empty, but returns the value UNtrimmed. */
    private function untrimmedStringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
    }
}
