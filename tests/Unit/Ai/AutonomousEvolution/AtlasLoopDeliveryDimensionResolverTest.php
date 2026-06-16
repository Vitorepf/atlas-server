<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryDimensionResolver;
use PHPUnit\Framework\TestCase;

/**
 * ACDE lever D2 — the single Rivals-free per-delivery dimension definition. A refusal and a canary-RED are
 * both defects; only attempted+committed+canary-not-red is clean; tie-break axes are clamped. No comparison
 * to any other engine (no head-to-head).
 */
final class AtlasLoopDeliveryDimensionResolverTest extends TestCase
{
    private function resolver(): AtlasLoopDeliveryDimensionResolver
    {
        return new AtlasLoopDeliveryDimensionResolver;
    }

    public function test_committed_green_is_clean(): void
    {
        $d = $this->resolver()->resolve(['attempted' => true, 'committed' => true, 'canary' => 'green', 'mutation_kill_ratio' => 0.8, 'completeness' => 1.0, 'cyclomatic_drop' => 4]);
        $this->assertTrue($d['clean']);
        $this->assertFalse($d['defect']);
        $this->assertNull($d['defect_reason']);
        $this->assertSame(0.8, $d['mutation_kill_ratio']);
    }

    public function test_refusal_is_a_defect(): void
    {
        $d = $this->resolver()->resolve(['attempted' => true, 'committed' => false]);
        $this->assertTrue($d['defect']);
        $this->assertFalse($d['clean']);
        $this->assertSame('refusal', $d['defect_reason']);
    }

    public function test_committed_but_canary_red_is_an_escaped_defect(): void
    {
        $d = $this->resolver()->resolve(['attempted' => true, 'committed' => true, 'canary' => 'RED']);
        $this->assertTrue($d['defect']);
        $this->assertFalse($d['clean']);
        $this->assertSame('canary_red', $d['defect_reason']);
    }

    public function test_axes_are_clamped(): void
    {
        $d = $this->resolver()->resolve(['attempted' => true, 'committed' => true, 'canary' => 'green', 'mutation_kill_ratio' => 1.5, 'completeness' => -0.5, 'cyclomatic_drop' => -3]);
        $this->assertSame(1.0, $d['mutation_kill_ratio']);
        $this->assertSame(0.0, $d['completeness']);
        $this->assertSame(0.0, $d['cyclomatic_drop']);
    }
}
