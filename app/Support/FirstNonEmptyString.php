<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Return the first non-empty trimmed string from a list of candidates.
 */
final class FirstNonEmptyString
{
    /**
     * @param  list<mixed>  $values
     */
    public static function from(array $values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
