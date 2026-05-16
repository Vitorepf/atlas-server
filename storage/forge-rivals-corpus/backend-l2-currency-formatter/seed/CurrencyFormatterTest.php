<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CurrencyFormatter;
use PHPUnit\Framework\TestCase;

final class CurrencyFormatterTest extends TestCase
{
    public function test_brl_pt_br(): void
    {
        $this->assertSame('R$ 1.234.567,89', CurrencyFormatter::format(123_456_789, 'BRL', 'pt_BR'));
    }

    public function test_usd_en_us(): void
    {
        $this->assertSame('$12,345.67', CurrencyFormatter::format(1_234_567, 'USD', 'en_US'));
    }

    public function test_eur_de_de(): void
    {
        $this->assertSame('12.345,67 €', CurrencyFormatter::format(1_234_567, 'EUR', 'de_DE'));
    }

    public function test_unknown_locale_falls_back_to_en_us_with_iso_suffix(): void
    {
        $this->assertSame('12,345.67 ZZD', CurrencyFormatter::format(1_234_567, 'ZZD', 'zz_ZZ'));
    }

    public function test_negative_value_keeps_minus_before_prefix(): void
    {
        $this->assertSame('-$1,234.50', CurrencyFormatter::format(-123_450, 'USD', 'en_US'));
    }

    public function test_unknown_locale_does_not_throw(): void
    {
        $output = CurrencyFormatter::format(100, 'XYZ', 'aa_AA');
        $this->assertNotEmpty($output);
    }
}
