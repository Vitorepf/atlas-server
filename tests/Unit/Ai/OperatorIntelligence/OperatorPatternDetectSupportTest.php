<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorPatternDetectSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorPatternDetectSupportTest extends TestCase
{
    #[Test]
    public function shingle_is_order_independent(): void
    {
        $a = OperatorPatternDetectSupport::shingle('prefiro respostas curtas e objetivas');
        $b = OperatorPatternDetectSupport::shingle('objetivas curtas respostas prefiro e');
        $this->assertSame($a, $b);
    }

    #[Test]
    public function regularity_is_higher_for_even_spacing(): void
    {
        $even = OperatorPatternDetectSupport::regularity(['2026-01-01', '2026-01-08', '2026-01-15', '2026-01-22']);
        $uneven = OperatorPatternDetectSupport::regularity(['2026-01-01', '2026-01-02', '2026-01-20', '2026-02-28']);
        $this->assertGreaterThan($uneven, $even);
    }
}
