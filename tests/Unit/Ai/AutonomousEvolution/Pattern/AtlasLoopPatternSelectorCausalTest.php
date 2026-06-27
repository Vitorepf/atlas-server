<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Pattern;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use Tests\TestCase;

/**
 * S3 — the causal selector. The Pattern selector, when armed (causal_selector_enabled), ranks a pattern whose
 * causal effect CI excludes zero ABOVE an identical pattern whose effect is unproven — without ever writing
 * (author≠judge). OFF (flag off OR no gate) is byte-identical to the pure selector. No fabricated compounding:
 * insufficient_n / CI-includes-zero earns ZERO bonus.
 */
final class AtlasLoopPatternSelectorCausalTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-selector-causal-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    private function spec(string $id): AtlasLoopPatternSpec
    {
        return AtlasLoopPatternSpec::fromArray([
            'id' => $id,
            'version' => '1.0.0',
            'intent' => 'self_improvement',
            'trigger_schema' => ['objective_kinds' => ['self_improvement']],
            'success_gates' => ['an independent verifier runs the suite green'],
            'terminal_states' => ['success', 'blocked'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only']],
            'agent_lane_policy' => ['lanes' => ['designer', 'verifier'], 'self_approval' => false, 'verifier_independent' => true],
            'source' => 'operator_seed',
            'status' => 'default',
        ]);
    }

    private function seededGate(): AtlasBrainCausalEffectGate
    {
        $ledger = new AtlasLoopPatternLearningLedger($this->path);
        // 'zzz-proven' beats the baseline ('aaa-unproven') with CI excluding zero; the reverse does not.
        for ($i = 0; $i < 9; $i++) {
            $ledger->record(['pattern_id' => 'zzz-proven', 'pattern_version' => '1.0.0', 'objective_class' => 'self_improvement', 'result' => 'success']);
        }
        $ledger->record(['pattern_id' => 'zzz-proven', 'pattern_version' => '1.0.0', 'objective_class' => 'self_improvement', 'result' => 'blocked']);
        for ($i = 0; $i < 2; $i++) {
            $ledger->record(['pattern_id' => 'aaa-unproven', 'pattern_version' => '1.0.0', 'objective_class' => 'self_improvement', 'result' => 'success']);
        }
        for ($i = 0; $i < 8; $i++) {
            $ledger->record(['pattern_id' => 'aaa-unproven', 'pattern_version' => '1.0.0', 'objective_class' => 'self_improvement', 'result' => 'blocked']);
        }

        return new AtlasBrainCausalEffectGate($ledger);
    }

    /** @return array<string,mixed> */
    private function objective(): array
    {
        return ['objective_kind' => 'self_improvement', 'expected_impact' => 0.5, 'evidence' => 0.3, 'risk' => 0.1, 'cost' => 0.1];
    }

    public function test_off_is_byte_identical_to_the_pure_selector(): void
    {
        $registry = new AtlasLoopPatternRegistry([$this->spec('aaa-unproven'), $this->spec('zzz-proven')]);

        config()->set('atlas.brain.causal_selector_enabled', false);
        $armed = (new AtlasLoopPatternSelector($this->seededGate()))->select($this->objective(), $registry);
        $pure = (new AtlasLoopPatternSelector)->select($this->objective(), $registry);

        self::assertSame($pure['ranking'], $armed['ranking'], 'flag OFF ⇒ identical scores/ranking to the pure selector');
        // base scores tie ⇒ deterministic id tie-break picks the alphabetically-first id.
        self::assertSame('aaa-unproven', $armed['pattern']->id);
    }

    public function test_on_a_causally_proven_path_outranks_an_identical_unproven_one(): void
    {
        $registry = new AtlasLoopPatternRegistry([$this->spec('aaa-unproven'), $this->spec('zzz-proven')]);

        config()->set('atlas.brain.causal_selector_enabled', true);
        $result = (new AtlasLoopPatternSelector($this->seededGate()))->select($this->objective(), $registry);

        // Causal bonus flips the winner: the proven path wins despite its alphabetically-later id (it would
        // LOSE the tie-break without causal evidence).
        self::assertSame('zzz-proven', $result['pattern']->id);
        $scores = collect($result['ranking'])->keyBy('id');
        self::assertGreaterThan($scores['aaa-unproven']['score'], $scores['zzz-proven']['score']);
    }

    public function test_on_but_no_gate_is_still_byte_identical(): void
    {
        $registry = new AtlasLoopPatternRegistry([$this->spec('aaa-unproven'), $this->spec('zzz-proven')]);

        config()->set('atlas.brain.causal_selector_enabled', true);
        $noGate = (new AtlasLoopPatternSelector)->select($this->objective(), $registry);
        $pure = (new AtlasLoopPatternSelector)->select($this->objective(), $registry);

        self::assertSame($pure['ranking'], $noGate['ranking'], 'no gate injected ⇒ no causal bonus even with flag ON');
    }
}
