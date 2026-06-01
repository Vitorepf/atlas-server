<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodeCategoryEvolutionService;
use Tests\TestCase;

/**
 * Pins the documented contract of the Atlas Code category-evolution doc: the
 * Category Stack mapping, the six-level Natural Progression ladder (with the
 * Atlas-band 4..6 vs lower 1..3 split), and the Boundary Rules that forbid the
 * specific over-claim at each Atlas-band level plus the "chat output is never
 * proof" and EOS-as-whole-area rules. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-code-category-evolution.md
 */
class AtlasCodeCategoryEvolutionTest extends TestCase
{
    private function service(): AtlasCodeCategoryEvolutionService
    {
        return new AtlasCodeCategoryEvolutionService;
    }

    public function test_category_stack_maps_eos_to_atlas_code_and_its_maturity_targets(): void
    {
        // Doc "Category Stack": EOS is the category; Atlas Code the surface;
        // SCOR-1 the first MVP; Operating Room the initial experience.
        $stack = $this->service()->categoryStack();

        $this->assertSame('Engineering Operations System', $stack['category']);
        $this->assertSame('Atlas Code', $stack['product_surface']);
        $this->assertSame('Atlas Code SCOR-1', $stack['first_enterprise_mvp']);
        $this->assertSame('Software Construction Operating Room', $stack['initial_experience']);
        $this->assertSame('Software Evolution Operating System', $stack['next_maturity']);
        $this->assertSame('Autonomous Software Organism', $stack['long_term_horizon']);
    }

    public function test_ladder_has_six_ordered_levels_with_atlas_band_4_to_6(): void
    {
        // Doc "Natural Progression": 6 levels; 1..3 are NOT Atlas Code (IDE,
        // AI-native IDE, AI coding agent); 4..6 are the Atlas Code band.
        $svc = $this->service();
        $this->assertCount(6, $svc->ladder());

        $this->assertSame('IDE', $svc->level(1)['name']);
        $this->assertFalse($svc->level(1)['atlas_band']);
        $this->assertSame('AI coding agent', $svc->level(3)['name']);
        $this->assertFalse($svc->level(3)['atlas_band']);

        $l4 = $svc->level(4);
        $this->assertSame('Engineering Operations System / Operating Room', $l4['name']);
        $this->assertSame('Can Atlas safely build this?', $l4['question']);
        $this->assertSame('Director and signer', $l4['human']);
        $this->assertTrue($l4['atlas_band']);

        $l6 = $svc->level(6);
        $this->assertSame('Autonomous Software Organism', $l6['name']);
        $this->assertTrue($l6['is_max']);

        // Unknown levels are reported, not invented.
        $this->assertFalse($svc->level(7)['known']);
        $this->assertFalse($svc->level(0)['known']);
    }

    public function test_level_4_claiming_self_evolution_violates_boundary_rule(): void
    {
        // Doc Boundary Rules: "Level 4 is not autonomous self-evolution; it is
        // governed construction."
        $r = $this->service()->classifyClaim([
            'level' => 4,
            'reached_levels' => [1, 2, 3],
            'framing' => 'Atlas Code is now self-evolving at the Operating Room level.',
        ]);

        $this->assertFalse($r['valid']);
        $this->assertSame('boundary_violation', $r['status']);
        $this->assertTrue($r['is_atlas_band']);
        $rules = array_column($r['violations'], 'rule');
        $this->assertContains('level_4_is_governed_construction_not_self_evolution', $rules);
    }

    public function test_clean_level_4_claim_with_predecessors_is_within_boundaries(): void
    {
        // Same level, governed framing, all lower levels reached -> valid.
        $r = $this->service()->classifyClaim([
            'level' => 4,
            'reached_levels' => [1, 2, 3],
            'framing' => 'Atlas Code safely builds under contract with signed receipts.',
        ]);

        $this->assertTrue($r['valid']);
        $this->assertSame('claim_within_boundaries', $r['status']);
        $this->assertSame([], $r['violations']);
    }

    public function test_skipping_a_lower_level_is_a_non_contiguous_violation(): void
    {
        // Ladder is a strict progression: claiming Level 5 while Level 3 is not
        // reached names the first gap.
        $r = $this->service()->classifyClaim([
            'level' => 5,
            'reached_levels' => [1, 2, 4], // level 3 missing
            'framing' => 'Software Evolution Operating System proposing evolution packets.',
        ]);

        $this->assertFalse($r['valid']);
        $skip = array_values(array_filter(
            $r['violations'],
            fn ($v) => $v['rule'] === 'non_contiguous_level_skip'
        ));
        $this->assertNotEmpty($skip);
        $this->assertSame(3, $skip[0]['first_gap_level']);
    }

    public function test_chat_as_proof_and_eos_as_whole_area_are_rejected(): void
    {
        // Doc Boundary Rules: chat output is never proof. Frontmatter
        // forbidden_changes: EOS must not stand in for the whole area.
        $r = $this->service()->classifyClaim([
            'level' => 6,
            'reached_levels' => [1, 2, 3, 4, 5],
            'framing' => 'Engineering Operations System is the whole Agentic Software Engineering area.',
            'proof_is_chat' => true,
        ]);

        $this->assertFalse($r['valid']);
        $rules = array_column($r['violations'], 'rule');
        $this->assertContains('chat_output_is_never_proof', $rules);
        $this->assertContains('eos_is_category_not_the_whole_engineering_area', $rules);
    }
}
