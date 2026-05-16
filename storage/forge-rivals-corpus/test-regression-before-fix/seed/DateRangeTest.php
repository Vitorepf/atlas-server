<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Suíte inicial. Falta o teste de regressão que prova o bug do
 * limite superior. O arm precisa ADICIONAR esse teste primeiro
 * (red), depois corrigir DateRange::contains() (green), sem modificar
 * os asserts existentes.
 */
final class DateRangeTest extends TestCase
{
    public function test_contains_includes_start(): void
    {
        $range = new DateRange(100, 200);
        $this->assertTrue($range->contains(100));
    }

    public function test_contains_includes_end_exact(): void
    {
        $range = new DateRange(100, 200);
        $this->assertTrue($range->contains(200));
    }

    public function test_contains_excludes_before_start(): void
    {
        $range = new DateRange(100, 200);
        $this->assertFalse($range->contains(99));
    }

    // O arm precisa adicionar AQUI:
    //   public function test_contains_excludes_dates_after_end(): void
    //   {
    //       $range = new DateRange(100, 200);
    //       $this->assertFalse($range->contains(201));
    //   }
    // Esse teste DEVE falhar antes do fix; depois do fix em DateRange::contains(),
    // todos os testes ficam verdes.
}
