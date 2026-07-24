<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parse PHP-style memory limit strings (e.g. 512M, 1G) into bytes.
 *
 * Full-pass reuse: de-duplicates private memoryLimitToBytes() copies across
 * HTTP controllers, Kernel architecture validation, and Engineering services.
 */
final class MemoryLimitBytes
{
    public static function parse(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $amount = (int) $value;

        return match ($unit) {
            'g' => $amount * 1024 * 1024 * 1024,
            'm' => $amount * 1024 * 1024,
            'k' => $amount * 1024,
            default => $amount,
        };
    }
}
