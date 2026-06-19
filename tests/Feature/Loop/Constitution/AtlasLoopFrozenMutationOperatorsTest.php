<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 3 — the frozen mutation snapshot pins the kill vocabulary, DECOUPLED from the
 * config-mutable live map: a flipped flag (or a newly added "extra" operator) can never alter the must-catch
 * region the battery generates neighbours from.
 */
final class AtlasLoopFrozenMutationOperatorsTest extends TestCase
{
    public function test_frozen_ids_are_the_core_always_on_set(): void
    {
        $ids = AtlasLoopFrozenMutationOperators::ids();
        $this->assertContains('return_true', $ids);
        $this->assertContains('strict_equals', $ids);
        $this->assertContains('gt_comparison', $ids);
        $this->assertCount(16, $ids, 'the 16 always-on operators');
        // the config-gated extras are DELIBERATELY excluded.
        $this->assertNotContains('exception_throw_noop', $ids);
        $this->assertNotContains('null_coalesce_null', $ids);
    }

    public function test_apply_runs_a_frozen_operator_via_the_single_source_transform(): void
    {
        $mutant = AtlasLoopFrozenMutationOperators::apply('return_true', "<?php\nfunction f(){ return true; }\n");
        $this->assertIsString($mutant);
        $this->assertStringContainsString('return false;', $mutant);
    }

    public function test_a_non_frozen_operator_is_refused_even_when_the_config_extra_flag_is_on(): void
    {
        // The decoupling proof: even with the extra operators armed in config, the frozen snapshot refuses a
        // non-frozen id — so the Constitution's must-catch region can never depend on a config flag.
        Config::set('atlas.loop.extra_mutation_operators_enabled', true);
        $this->assertNull(AtlasLoopFrozenMutationOperators::apply('exception_throw_noop', "<?php\nthrow new \\Exception('x');\n"));
    }

    public function test_frozen_ids_match_the_live_base_map_drift_tripwire(): void
    {
        // RE-ATTESTATION TRIPWIRE: the frozen snapshot is a verbatim copy of the live always-on map. If
        // someone changes the live moat (adds/renames/removes a base operator) without deliberately
        // re-freezing here, this goes RED — the only thing that keeps the two copies in lockstep now that
        // the frozen class no longer delegates to the live class.
        Config::set('atlas.loop.extra_mutation_operators_enabled', false);
        $this->assertSame(
            array_keys(AtlasLoopMutationOperators::map()),
            AtlasLoopFrozenMutationOperators::FROZEN_OPERATOR_IDS,
            'live always-on operator set drifted from the frozen snapshot — re-attest the copy',
        );
    }

    public function test_frozen_transforms_match_the_live_transforms_verbatim(): void
    {
        // Proves the COPY is faithful today (and stays a tripwire): every frozen operator produces the exact
        // same byte output as the live operator on a rich sample — so the verbatim copy is not a silent
        // divergence. (At runtime the frozen class never calls the live one; this test is the only coupling.)
        $samples = [
            "<?php\nfunction f(int \$a): bool { if (\$a === 1) { return true; } return false; }\n",
            "<?php\n\$n = 5; if (\$n >= 0 && \$n > 0) { Job::dispatch(); } \$x = 'hi'; return 7;\n",
            "<?php\n\$q->insert(['a' => 1]); return \$a !== \$b;\n",
        ];
        foreach (AtlasLoopFrozenMutationOperators::FROZEN_OPERATOR_IDS as $id) {
            foreach ($samples as $src) {
                $this->assertSame(
                    AtlasLoopMutationOperators::applyOperator($id, $src),
                    AtlasLoopFrozenMutationOperators::apply($id, $src),
                    "frozen transform '$id' diverged from the live transform",
                );
            }
        }
    }

    public function test_neighborhood_returns_the_applicable_radius_1_mutants(): void
    {
        $src = "<?php\nfunction f(int \$a): bool { if (\$a === 1) { return true; } return false; }\n";
        $hood = AtlasLoopFrozenMutationOperators::neighborhood($src);

        $this->assertArrayHasKey('strict_equals', $hood, '=== flips to !==');
        $this->assertArrayHasKey('return_true', $hood);
        foreach ($hood as $mutant) {
            $this->assertNotSame($src, $mutant, 'every neighbour actually changes the source');
        }
    }
}
