<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyLevelLadder;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasSelfConstructionAutonomyLevelLadderTest extends TestCase
{
    public function test_levels_are_in_deterministic_order_with_increasing_rank(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        self::assertSame(
            [
                AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
                AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED,
                AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
                AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
                AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            ],
            $ladder->order(),
        );

        $levels = $ladder->levels();
        self::assertCount(5, $levels);
        $previousRank = -1;
        foreach ($levels as $level) {
            self::assertGreaterThan($previousRank, $level['rank']);
            self::assertNotEmpty($level['allowed_capabilities']);
            self::assertNotEmpty($level['forbidden_capabilities']);
            self::assertNotEmpty($level['evidence_prerequisites']);
            self::assertNotEmpty($level['stop_conditions']);
            self::assertArrayHasKey('final_runtime_owner', $level);
            self::assertArrayHasKey('steady_state_runtime_owner', $level);
            $previousRank = $level['rank'];
        }
    }

    public function test_final_atlas_native_levels_expose_native_owner_and_zero_autonomy_dependencies(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        foreach ([
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
        ] as $level) {
            $desc = $ladder->describe($level);

            self::assertSame(
                AtlasSelfConstructionAutonomyLevelLadder::FINAL_OWNER_ATLAS_NATIVE,
                $desc['final_runtime_owner'],
                "{$level} must expose final_runtime_owner=atlas_native",
            );
            self::assertSame(
                AtlasSelfConstructionAutonomyLevelLadder::STEADY_STATE_RUNTIME_OWNER,
                $desc['steady_state_runtime_owner'],
                "{$level} must expose steady_state_runtime_owner=atlas_server",
            );
            foreach ($desc['autonomy_dependencies'] as $flag => $value) {
                self::assertFalse($value, "{$level} autonomy dependency '{$flag}' must be false");
            }
        }
    }

    public function test_transition_allows_only_next_level_promotions(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED,
        );
        self::assertTrue($verdict['allowed']);
        self::assertNull($verdict['reason']);
    }

    public function test_transition_refuses_level_skip(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
        );

        self::assertFalse($verdict['allowed']);
        self::assertSame('level_skip_refused', $verdict['reason']);
    }

    public function test_transition_refuses_downgrade(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
        );

        self::assertFalse($verdict['allowed']);
        self::assertSame('downgrade_refused', $verdict['reason']);
    }

    public function test_transition_refuses_unknown_level(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $verdict = $ladder->transition('bogus', AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP);

        self::assertFalse($verdict['allowed']);
        self::assertSame('unknown_source_level', $verdict['reason']);
    }

    public function test_transition_refuses_noop_at_same_level(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED,
        );

        self::assertFalse($verdict['allowed']);
        self::assertSame('noop_refused', $verdict['reason']);
    }

    public function test_describe_throws_on_unknown_level(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $this->expectException(InvalidArgumentException::class);
        $ladder->describe('atlas_omniscient');
    }

    public function test_bootstrap_and_assisted_have_external_runtime_owners(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $bootstrap = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP);
        self::assertNotSame(AtlasSelfConstructionAutonomyLevelLadder::FINAL_OWNER_ATLAS_NATIVE, $bootstrap['final_runtime_owner']);

        $assisted = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED);
        self::assertNotSame(AtlasSelfConstructionAutonomyLevelLadder::FINAL_OWNER_ATLAS_NATIVE, $assisted['final_runtime_owner']);
        self::assertTrue($assisted['autonomy_dependencies']['depends_on_claude_code']);
        self::assertTrue($assisted['autonomy_dependencies']['depends_on_codex']);
    }
}
