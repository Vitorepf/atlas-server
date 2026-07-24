<?php

declare(strict_types=1);

namespace App\Support;

/** Validate Y-m-d or default to UTC today. */
final class YmdDay
{
    public static function normalize(string $day): string
    {
        $day = trim($day);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : gmdate('Y-m-d');
    }
}
