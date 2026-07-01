<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueGateBacktestReplayTest extends TestCase
{
    private AtlasExternalBrainValueGateBacktestReplay $replay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replay = new AtlasExternalBrainValueGateBacktestReplay();
    }

    // AC 2: tightening includes replay_reason and prevented_failure_modes for admitted poison
    public function test_tightening_for_poison_includes_reason_and_modes(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 3,
            'poison_examples' => ['petreo_target', 'duplicate_task', 'test_only'],
            'rejected_green_count' => 0,
        ]);

        $this->assertSame('tighten_risk_ceiling', $result['action']);
        $this->assertNotNull($result['adjustment']);
        $this->assertNotEmpty($result['adjustment']['replay_reason']);
        $this->assertNotEmpty($result['adjustment']['prevented_failure_modes']);
        $this->assertContains('petreo_target', $result['adjustment']['prevented_failure_modes']);
    }

    // AC 3: loosening includes false_negative_risk when green tasks rejected
    public function test_loosening_for_green_includes_false_negative_risk(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 0,
            'rejected_green_count' => 5,
            'total_admitted' => 10,
            'green_examples' => ['high_value_a', 'high_value_b'],
        ]);

        $this->assertSame('loosen_impact_floor', $result['action']);
        $this->assertNotNull($result['adjustment']);
        $this->assertGreaterThan(0, $result['adjustment']['false_negative_risk']);
    }

    // AC 4: no adjustment without non-empty audit_reason
    public function test_no_adjustment_has_non_empty_audit_reason(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 0,
            'rejected_green_count' => 0,
        ]);

        $this->assertSame('no_adjustment', $result['action']);
        $this->assertNotEmpty($result['audit_reason']);
    }

    public function test_tightening_has_non_empty_audit_reason(): void
    {
        $result = $this->replay->replay(['admitted_poison_count' => 1]);

        $this->assertNotEmpty($result['audit_reason']);
    }

    public function test_empty_backtest_is_no_adjustment(): void
    {
        $result = $this->replay->replay([]);

        $this->assertSame('no_adjustment', $result['action']);
        $this->assertNull($result['adjustment']);
    }
}
