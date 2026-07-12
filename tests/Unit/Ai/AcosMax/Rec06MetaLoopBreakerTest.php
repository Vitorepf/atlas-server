<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Governance\Recursion\MetaLoopBreakerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Rec06MetaLoopBreakerTest extends TestCase
{
    #[Test]
    public function no_real_series_keeps_breakers_disarmed_with_insufficient_signal(): void
    {
        $report = (new MetaLoopBreakerService)->evaluate([]);

        $this->assertSame('atlas.acos.rec06.meta_loop_breakers.v1', $report['schema_version']);
        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertFalse($report['armed']);
        $this->assertSame('missing_real_series', $report['reason']);
        foreach ($report['breakers'] as $breaker) {
            $this->assertSame('disarmed', $breaker['state']);
            $this->assertSame('insufficient_signal', $breaker['basis']);
        }
    }

    #[Test]
    public function unmeasured_series_do_not_arm_breakers(): void
    {
        $report = (new MetaLoopBreakerService)->evaluate([
            'voi' => ['status' => 'insufficient_signal'],
            'r' => ['status' => 'unmeasurable'],
            'm_operator' => ['status' => 'insufficient_signal'],
        ]);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertFalse($report['armed']);
        $this->assertSame('series_not_measured', $report['reason']);
    }

    #[Test]
    public function measured_negative_value_arms_named_breakers(): void
    {
        $report = (new MetaLoopBreakerService)->evaluate([
            'voi' => ['status' => 'ok', 'top_voi' => 0.005, 'denominator' => 4],
            'r' => ['status' => 'measured', 'r' => -0.1, 'denominator_effective' => 8.0],
            'm_operator' => ['status' => 'measured', 'm_operator' => 0.90, 'm_neutral' => 1.20, 'divergence' => -0.30],
        ]);

        $this->assertSame('armed', $report['status']);
        $this->assertTrue($report['armed']);
        $this->assertSame('armed', $report['breakers']['low_voi']['state']);
        $this->assertSame('armed', $report['breakers']['non_positive_r']['state']);
        $this->assertSame('armed', $report['breakers']['operator_m_under_neutral']['state']);
        $this->assertContains('pause_meta_loop', $report['actions']);
    }
}
