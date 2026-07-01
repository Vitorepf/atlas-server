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

    public function test_evaluate_promotes_at_delivery_threshold(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $result = $ladder->evaluate([
            'current_level' => AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
            'green_deliveries' => AtlasSelfConstructionAutonomyLevelLadder::PROMOTION_DELIVERY_THRESHOLD,
        ]);

        self::assertSame(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED, $result['level']);
        self::assertFalse($result['is_paused']);
        self::assertFalse($result['is_degraded']);
        self::assertStringContainsString('promote', $result['reasons'][0]);
    }

    public function test_evaluate_degrades_at_failure_threshold(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $result = $ladder->evaluate([
            'current_level' => AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            'failures' => AtlasSelfConstructionAutonomyLevelLadder::DEGRADATION_FAILURE_THRESHOLD,
        ]);

        self::assertSame('degraded', $result['level']);
        self::assertTrue($result['is_degraded']);
        self::assertStringContainsString('degraded', $result['reasons'][0]);
    }

    public function test_evaluate_pauses_at_safety_threshold(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $result = $ladder->evaluate([
            'current_level' => AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            'safety_stops' => AtlasSelfConstructionAutonomyLevelLadder::PAUSED_SAFETY_THRESHOLD,
        ]);

        self::assertSame('paused', $result['level']);
        self::assertTrue($result['is_paused']);
        self::assertStringContainsString('paused', $result['reasons'][0]);
    }

    // ── AC: each level exposes required_evidence, forbidden_shortcuts, rollback_expectation, promotion_threshold ──

    public function test_every_level_exposes_required_evidence_forbidden_shortcuts_rollback_expectation_and_promotion_threshold(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        foreach ($ladder->levels() as $level) {
            $this->assertNotEmpty($level['required_evidence'], "{$level['level']} missing required_evidence");
            $this->assertNotEmpty($level['forbidden_shortcuts'], "{$level['level']} missing forbidden_shortcuts");
            $this->assertArrayHasKey('rollback_expectation', $level);
            $this->assertIsString($level['rollback_expectation']);
            $this->assertNotSame('', $level['rollback_expectation']);
            $this->assertArrayHasKey('promotion_threshold', $level);
            $this->assertIsString($level['description']);
            $this->assertNotSame('', $level['description'], "{$level['level']} missing provider-safe description");
        }
    }

    public function test_terminal_level_has_null_promotion_threshold(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $terminal = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7);

        $this->assertNull($terminal['promotion_threshold']);
    }

    public function test_promotion_thresholds_increase_toward_terminal_level(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $bootstrap = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP)['promotion_threshold'];
        $assisted = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED)['promotion_threshold'];
        $supervised = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED)['promotion_threshold'];
        $bounded = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED)['promotion_threshold'];

        $this->assertLessThan($assisted, $bootstrap);
        $this->assertLessThan($supervised, $assisted);
        $this->assertLessThan($bounded, $supervised);
    }

    // ── AC: describe ──────────────────────────────────────────────────────────────

    public function test_describe_returns_a_single_level_with_full_contract(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $level = $ladder->describe(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED);

        $this->assertSame(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED, $level['level']);
        $this->assertContains('bypass_operator_merge_approval', $level['forbidden_shortcuts']);
    }

    // ── AC: levels ────────────────────────────────────────────────────────────────

    public function test_levels_returns_full_ordered_ladder(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();

        $this->assertCount(5, $ladder->levels());
    }

    // ── AC: valid transition ─────────────────────────────────────────────────────

    public function test_valid_transition_is_allowed(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
        );

        $this->assertTrue($verdict['allowed']);
    }

    // ── AC: blocked transition ────────────────────────────────────────────────────

    public function test_blocked_transition_carries_a_reason(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $verdict = $ladder->transition(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
        );

        $this->assertFalse($verdict['allowed']);
        $this->assertNotEmpty($verdict['reason']);
    }

    // ── AC: evaluate output for insufficient evidence ────────────────────────────

    public function test_evaluate_holds_when_no_threshold_is_reached(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder();
        $result = $ladder->evaluate([
            'current_level' => AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED,
            'green_deliveries' => 0,
            'failures' => 0,
            'safety_stops' => 0,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ASSISTED, $result['level']);
        $this->assertFalse($result['is_paused']);
        $this->assertFalse($result['is_degraded']);
        $this->assertStringContainsString('insufficient_evidence', $result['reasons'][0]);
    }
}
