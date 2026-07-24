<?php

declare(strict_types=1);

namespace App\Support;

/** Round a nullable float to 3 decimals (ledger stats). */
final class RoundOrNull
{
    public static function of(?float $value, int $precision = 3): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
