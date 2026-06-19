<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use Tests\TestCase;

/**
 * SLICE C-territory-ladder — proves the atomic three-set promotion invariant of the ARMED territory ladder.
 *
 * The load-bearing safety check: a widened discovery root with NO frozen safety file under it is REJECTED
 * (the loop could edit the new territory's judge). Behavioral + non-vacuous — exact booleans + exact
 * violation sets, never just "not empty".
 */
final class AtlasLoopTerritoryLadderTest extends TestCase
{
    private function ladder(): AtlasLoopTerritoryLadder
    {
        return new AtlasLoopTerritoryLadder;
    }

    public function test_widened_root_without_frozen_safety_file_is_rejected_and_names_the_root(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'memory-territory',
            'discovery_roots' => ['app/Services/Memory'],
            'frozen_safety_files' => [], // root is widened but its judge is NOT frozen
            'robustness_cases' => 2,
            'certified_leaps' => 5,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertFalse($result['invariant_holds'], 'unfrozen new-territory judge must break the invariant');
        $this->assertFalse($result['promotable'], 'invariant broken => not promotable even with a clean promotion rule');
        $this->assertTrue($result['promotion_rule_met'], 'the promotion rule itself is satisfied here');
        $this->assertSame(['unprotected_root:app/Services/Memory'], $result['violations']);
    }

    public function test_fully_frozen_territory_with_enough_leaps_zero_red_main_and_trend_up_is_promotable(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'dev-territory',
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'robustness_cases' => 1,
            'certified_leaps' => 3,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertTrue($result['invariant_holds']);
        $this->assertTrue($result['promotion_rule_met']);
        $this->assertTrue($result['promotable']);
        $this->assertSame([], $result['violations']);
    }

    public function test_same_fully_frozen_territory_with_one_red_main_is_not_promotable(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'dev-territory',
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'robustness_cases' => 1,
            'certified_leaps' => 3,
            'red_main_in_window' => 1, // one red main in the window kills the promotion rule
            'compounding_trend_up' => true,
        ]);

        $this->assertTrue($result['invariant_holds'], 'safety invariant still holds — only the promotion rule fails');
        $this->assertFalse($result['promotion_rule_met']);
        $this->assertFalse($result['promotable']);
        $this->assertSame(['red_main_in_window:1'], $result['violations']);
    }

    public function test_zero_robustness_cases_breaks_the_invariant(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'dev-territory',
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'robustness_cases' => 0, // no robustness corpus => invariant breaks
            'certified_leaps' => 3,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertFalse($result['invariant_holds']);
        $this->assertFalse($result['promotable']);
        $this->assertTrue($result['promotion_rule_met']);
        $this->assertSame(['no_robustness_case'], $result['violations']);
    }

    public function test_insufficient_certified_leaps_fails_promotion_rule_under_default_k(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'dev-territory',
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'robustness_cases' => 1,
            'certified_leaps' => 2, // below default K=3
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertTrue($result['invariant_holds']);
        $this->assertFalse($result['promotion_rule_met']);
        $this->assertFalse($result['promotable']);
        $this->assertSame(['insufficient_certified_leaps:2/3'], $result['violations']);
    }

    public function test_multi_root_promotion_only_names_the_unprotected_root(): void
    {
        // Two roots: one frozen, one not. Only the unprotected one is a violation.
        $result = $this->ladder()->canPromote([
            'name' => 'two-root-territory',
            'discovery_roots' => ['app/Services/Frozen', 'app/Services/Open'],
            'frozen_safety_files' => ['app/Services/Frozen/Judge.php'],
            'robustness_cases' => 2,
            'certified_leaps' => 4,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertFalse($result['invariant_holds']);
        $this->assertFalse($result['promotable']);
        $this->assertSame(['unprotected_root:app/Services/Open'], $result['violations']);
    }

    public function test_prefix_sibling_directory_does_not_count_as_frozen(): void
    {
        // 'app/Services/Foo' is widened; the only frozen file is under the SIBLING 'app/Services/FooBar'.
        // Naive prefix matching would wrongly accept it; the trailing-slash guard rejects it.
        $result = $this->ladder()->canPromote([
            'name' => 'prefix-trap',
            'discovery_roots' => ['app/Services/Foo'],
            'frozen_safety_files' => ['app/Services/FooBar/Judge.php'],
            'robustness_cases' => 1,
            'certified_leaps' => 3,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertFalse($result['invariant_holds'], 'a sibling directory must not satisfy under-root freezing');
        $this->assertSame(['unprotected_root:app/Services/Foo'], $result['violations']);
    }

    public function test_compounding_trend_down_fails_only_the_promotion_rule(): void
    {
        $result = $this->ladder()->canPromote([
            'name' => 'dev-territory',
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'robustness_cases' => 1,
            'certified_leaps' => 3,
            'red_main_in_window' => 0,
            'compounding_trend_up' => false, // trend not up
        ]);

        $this->assertTrue($result['invariant_holds']);
        $this->assertFalse($result['promotion_rule_met']);
        $this->assertFalse($result['promotable']);
        $this->assertSame(['compounding_trend_not_up'], $result['violations']);
    }
}
