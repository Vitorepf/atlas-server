<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;
use Tests\TestCase;

/**
 * Keystone #4 — the causal effect gate. Proves it refuses on small n, ADMITS only when the two-proportion CI
 * excludes zero on the positive side, distinguishes a worse path from a promising-but-unproven one, respects
 * objective-class slicing + IRM-lite invariance, and is a pétreo forbidden self-target.
 */
final class AtlasBrainCausalEffectGateTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-pattern-ledger-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    private function ledger(): AtlasLoopPatternLearningLedger
    {
        return new AtlasLoopPatternLearningLedger($this->path);
    }

    /** @param non-empty-string $class */
    private function seedRuns(AtlasLoopPatternLearningLedger $ledger, string $patternId, int $successes, int $failures, string $class = 'refactor'): void
    {
        for ($i = 0; $i < $successes; $i++) {
            $ledger->record(['pattern_id' => $patternId, 'pattern_version' => '1.0.0', 'objective_class' => $class, 'result' => AtlasLoopPatternLearningLedger::RESULT_SUCCESS]);
        }
        for ($i = 0; $i < $failures; $i++) {
            $ledger->record(['pattern_id' => $patternId, 'pattern_version' => '1.0.0', 'objective_class' => $class, 'result' => AtlasLoopPatternLearningLedger::RESULT_BLOCKED]);
        }
    }

    public function test_refuses_on_insufficient_n(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'pathA', 2, 1); // n_treat = 3 < MIN_N
        $this->seedRuns($ledger, 'pathB', 8, 2);

        $verdict = (new AtlasBrainCausalEffectGate($ledger))->effect('pathA');

        self::assertFalse($verdict['admit_compounding']);
        self::assertSame('insufficient_n', $verdict['reason']);
    }

    public function test_admits_a_path_whose_ci_excludes_zero_positive(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'pathA', 9, 1);  // p ≈ 0.9
        $this->seedRuns($ledger, 'pathB', 2, 8);  // baseline p ≈ 0.2

        $verdict = (new AtlasBrainCausalEffectGate($ledger))->effect('pathA');

        self::assertTrue($verdict['admit_compounding']);
        self::assertSame('admitted', $verdict['reason']);
        self::assertGreaterThan(0.0, $verdict['ci_low']);
    }

    public function test_promising_but_uncertain_is_not_admitted(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'pathA', 3, 2);  // p = 0.6, small n
        $this->seedRuns($ledger, 'pathB', 2, 3);  // p = 0.4

        $verdict = (new AtlasBrainCausalEffectGate($ledger))->effect('pathA');

        self::assertFalse($verdict['admit_compounding']);
        self::assertSame('ci_includes_zero', $verdict['reason']);
        self::assertGreaterThan(0.0, $verdict['effect']); // positive point estimate, but not proven
    }

    public function test_a_worse_path_reads_as_effect_not_positive(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'pathA', 1, 9);  // p = 0.1
        $this->seedRuns($ledger, 'pathB', 9, 1);  // baseline p = 0.9

        $verdict = (new AtlasBrainCausalEffectGate($ledger))->effect('pathA');

        self::assertFalse($verdict['admit_compounding']);
        self::assertSame('effect_not_positive', $verdict['reason']);
        self::assertLessThan(0.0, $verdict['effect']);
    }

    public function test_objective_class_slices_the_estimate(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'pathA', 9, 1, 'refactor'); // strong on refactor
        $this->seedRuns($ledger, 'pathB', 2, 8, 'refactor');
        $this->seedRuns($ledger, 'pathA', 1, 9, 'bug');      // weak on bug
        $this->seedRuns($ledger, 'pathB', 8, 2, 'bug');

        $gate = new AtlasBrainCausalEffectGate($ledger);

        self::assertTrue($gate->effect('pathA', 'refactor')['admit_compounding']);
        self::assertFalse($gate->effect('pathA', 'bug')['admit_compounding']);
    }

    public function test_invariant_positive_requires_the_effect_across_classes(): void
    {
        $ledger = $this->ledger();
        // pathA wins in BOTH classes → invariant
        $this->seedRuns($ledger, 'pathA', 9, 1, 'refactor');
        $this->seedRuns($ledger, 'pathA', 9, 1, 'bug');
        // baseline weak in both
        $this->seedRuns($ledger, 'pathB', 1, 9, 'refactor');
        $this->seedRuns($ledger, 'pathB', 1, 9, 'bug');
        // pathC wins only in refactor → not invariant
        $this->seedRuns($ledger, 'pathC', 9, 1, 'refactor');
        $this->seedRuns($ledger, 'pathC', 5, 5, 'bug');

        $gate = new AtlasBrainCausalEffectGate($ledger);

        self::assertTrue($gate->isInvariantPositive('pathA', 2));
        self::assertFalse($gate->isInvariantPositive('pathC', 2));
    }

    public function test_causal_gate_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCausalEffectGate.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
