<?php

namespace Tests\Unit;

use App\Services\Bitacula\CanonicalBehaviorCatalog;
use PHPUnit\Framework\TestCase;

class CanonicalBehaviorCatalogTest extends TestCase
{
    public function test_normalizes_terere_after_16_to_late_caffeine(): void
    {
        $catalog = new CanonicalBehaviorCatalog;

        $result = $catalog->normalize('tereré depois das 16 horas');

        $this->assertSame('caffeine_late', $result['suggestions'][0]['id']);
        $this->assertSame('Cafeína após 14h', $result['suggestions'][0]['label']);
        $this->assertSame('substancias', $result['suggestions'][0]['category']);
        $this->assertSame('caffeine', $result['suggestions'][0]['parent_factor']);
        $this->assertSame('after_14h', $result['suggestions'][0]['factor_condition']);
    }

    public function test_can_return_multiple_factors_from_one_text(): void
    {
        $catalog = new CanonicalBehaviorCatalog;

        $result = $catalog->normalize('tomei vinho e usei celular na cama');
        $ids = array_column($result['suggestions'], 'id');

        $this->assertContains('alcohol_any', $ids);
        $this->assertContains('screen_in_bed', $ids);
    }

    public function test_normalizes_recovery_protocol(): void
    {
        $catalog = new CanonicalBehaviorCatalog;

        $result = $catalog->normalize('fiz sauna e banho frio');

        $this->assertSame('recovery_protocol', $result['suggestions'][0]['id']);
        $this->assertSame('recuperacao', $result['suggestions'][0]['category']);
    }
}
