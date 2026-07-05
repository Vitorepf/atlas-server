<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAutonomyLeveragePicker;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilAutonomyLeveragePickerTest extends TestCase
{
    private AtlasStrategyCouncilAutonomyLeveragePicker $picker;

    protected function setUp(): void
    {
        $this->picker = new AtlasStrategyCouncilAutonomyLeveragePicker;
    }

    public function test_readiness_blockers_produce_ranked_recommendation(): void
    {
        $result = $this->picker->pick(['readiness_blocker_count' => 2]);

        $this->assertSame('readiness_blocker', $result['top_leverage']);
    }

    public function test_outcome_regressions_produce_ranked_recommendation(): void
    {
        $result = $this->picker->pick(['outcome_regression_count' => 1]);

        $this->assertSame('outcome_regression', $result['top_leverage']);
    }

    public function test_queue_damage_produces_ranked_recommendation(): void
    {
        $result = $this->picker->pick(['queue_damage_count' => 3]);

        $this->assertSame('queue_damage', $result['top_leverage']);
    }

    public function test_no_issues_produces_steady_state(): void
    {
        $result = $this->picker->pick([]);

        $this->assertSame('steady_state', $result['top_leverage']);
    }

    public function test_readiness_outranks_outcome(): void
    {
        $result = $this->picker->pick([
            'readiness_blocker_count' => 1,
            'outcome_regression_count' => 1,
        ]);

        $this->assertSame('readiness_blocker', $result['top_leverage']);
    }

    public function test_schema_present(): void
    {
        $result = $this->picker->pick([]);
        $this->assertSame(AtlasStrategyCouncilAutonomyLeveragePicker::SCHEMA, $result['schema']);
    }
}
