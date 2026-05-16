<?php

declare(strict_types=1);

namespace Tests\Unit\Pagination;

use App\Services\Pagination\PageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Regression suite para o bug do paginator off-by-one.
 *
 * Comportamento esperado depois do fix:
 *  - count multiplo do perPage devolve count / perPage (não count / perPage - 1).
 *  - count nao-multiplo arredonda para cima.
 *  - count == 0 devolve 1 (página em branco).
 */
final class PageCalculatorTest extends TestCase
{
    public function test_last_page_rounds_up_without_off_by_one(): void
    {
        $this->assertSame(2, (new PageCalculator)->lastPage(20, 10), '20 itens em pages de 10 = 2 pages');
        $this->assertSame(3, (new PageCalculator)->lastPage(21, 10), '21 itens em pages de 10 = 3 pages');
        $this->assertSame(4, (new PageCalculator)->lastPage(40, 10), '40 itens em pages de 10 = 4 pages (era 3 com bug)');
    }

    public function test_last_page_for_empty_count_returns_one(): void
    {
        $this->assertSame(1, (new PageCalculator)->lastPage(0, 10));
    }

    public function test_last_page_for_invalid_per_page_returns_one(): void
    {
        $this->assertSame(1, (new PageCalculator)->lastPage(50, 0));
    }

    public function test_last_page_for_single_item_returns_one(): void
    {
        $this->assertSame(1, (new PageCalculator)->lastPage(1, 10));
    }
}
