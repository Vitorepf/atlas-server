<?php

declare(strict_types=1);

namespace App\Support;

final class CurrencyFormatter
{
    /**
     * SEED: returns the raw cent count. Replace with the locale-aware
     * formatter described in README.md.
     */
    public static function format(int $cents, string $currency, string $locale): string
    {
        return (string) $cents;
    }
}
