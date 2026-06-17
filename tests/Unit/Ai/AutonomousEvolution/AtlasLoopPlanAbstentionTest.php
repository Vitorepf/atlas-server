<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use PHPUnit\Framework\TestCase;

/**
 * ACDE P4 — the plan-abstention receipt makes a structured-planner give-up VISIBLE (objective family + reason)
 * instead of a silent dumb-buildPlan fallback. Pure receipt builder => hang-free.
 */
final class AtlasLoopPlanAbstentionTest extends TestCase
{
    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new AtlasLoopObraExecutionAdapter;
    }

    public function test_receipt_carries_family_and_reason(): void
    {
        $r = $this->adapter()->planAbstentionReceipt(['objective_kind' => 'refactor_extract_class'], 'plan_not_ready');

        $this->assertTrue($r['abstained']);
        $this->assertSame('refactor_extract_class', $r['objective_kind']);
        $this->assertSame('refactor', $r['family']);
        $this->assertSame('plan_not_ready', $r['reason']);
    }

    public function test_feature_family_and_spec_reason(): void
    {
        $r = $this->adapter()->planAbstentionReceipt(['objective_kind' => 'feature_sequenced'], 'spec_not_ready');

        $this->assertSame('feature', $r['family']);
        $this->assertSame('spec_not_ready', $r['reason']);
    }

    public function test_missing_kind_and_reason_default_to_unknown(): void
    {
        $r = $this->adapter()->planAbstentionReceipt([], '');

        $this->assertSame('', $r['objective_kind']);
        $this->assertSame('unknown', $r['family']);
        $this->assertSame('unspecified', $r['reason']);
    }
}
