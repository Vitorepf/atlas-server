<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeEnterpriseExcellenceChecklistService as Checklist;
use Tests\TestCase;

/**
 * Pins the documented Cognitive Runtime Enterprise Excellence Checklist rules:
 * the ten areas, the baseline/atlas_plus/proof ladder, the Executive Score
 * implemented gate, and the Superation Rule (all ten areas + non-skippable
 * pillars).
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
 */
class AtlasCognitiveRuntimeEnterpriseExcellenceChecklistTest extends TestCase
{
    private function service(): Checklist
    {
        return new Checklist();
    }

    /**
     * The doc declares exactly 10 areas, 3 levels per area, and 4 non-skippable
     * pillars (evidence/review/privacy/replay).
     */
    public function test_documented_catalog_sizes(): void
    {
        $this->assertCount(10, Checklist::AREAS);
        $this->assertCount(3, Checklist::LEVELS);
        $this->assertCount(4, Checklist::NON_SKIPPABLE_PILLARS);
        $this->assertSame(
            ['evidence', 'review', 'privacy', 'replay'],
            Checklist::NON_SKIPPABLE_PILLARS
        );
    }

    /**
     * The three-level ladder is enforced: an area is enterprise_ready ONLY with
     * all three levels, and higher levels do not count when a lower one is absent
     * ("nao marcar implementado sem ... evidence"). Proof without baseline is
     * still below_baseline, not unproven/ready.
     */
    public function test_area_ladder_requires_all_three_levels_in_order(): void
    {
        $service = $this->service();

        // All three -> enterprise_ready.
        $full = $service->classifyArea([
            Checklist::LEVEL_BASELINE => true,
            Checklist::LEVEL_ATLAS_PLUS => true,
            Checklist::LEVEL_PROOF => true,
        ]);
        $this->assertSame(Checklist::AREA_ENTERPRISE_READY, $full['maturity']);
        $this->assertTrue($full['enterprise_ready']);
        $this->assertSame([], $full['missing_levels']);

        // baseline + atlas_plus, no proof -> unproven (cannot be declared ready).
        $unproven = $service->classifyArea([
            Checklist::LEVEL_BASELINE => true,
            Checklist::LEVEL_ATLAS_PLUS => true,
            Checklist::LEVEL_PROOF => false,
        ]);
        $this->assertSame(Checklist::AREA_UNPROVEN, $unproven['maturity']);
        $this->assertFalse($unproven['enterprise_ready']);
        $this->assertSame([Checklist::LEVEL_PROOF], $unproven['missing_levels']);

        // proof flag set but baseline absent -> ladder rejects it: below_baseline,
        // and proof is reported as NOT satisfied.
        $skippedLadder = $service->classifyArea([
            Checklist::LEVEL_BASELINE => false,
            Checklist::LEVEL_ATLAS_PLUS => true,
            Checklist::LEVEL_PROOF => true,
        ]);
        $this->assertSame(Checklist::AREA_BELOW_BASELINE, $skippedLadder['maturity']);
        $this->assertFalse($skippedLadder['levels'][Checklist::LEVEL_PROOF]);
        $this->assertContains(Checklist::LEVEL_BASELINE, $skippedLadder['missing_levels']);
    }

    /**
     * Executive Score: planned/partial/designed/research_mapped may NOT be counted
     * as implemented and require an AP / contrato filho first; only "implemented"
     * counts.
     */
    public function test_executive_status_gates_non_implemented(): void
    {
        $service = $this->service();

        foreach (['planned', 'partial', 'designed', 'research_mapped'] as $status) {
            $row = $service->classifyExecutiveStatus($status);
            $this->assertFalse($row['counts_as_implemented'], "$status must not count as implemented");
            $this->assertTrue($row['needs_ap_or_child_contract_first'], "$status must require AP first");
        }

        $implemented = $service->classifyExecutiveStatus('Implemented');
        $this->assertTrue($implemented['counts_as_implemented']);
        $this->assertFalse($implemented['needs_ap_or_child_contract_first']);
    }

    /**
     * Superation Rule: Atlas is enterprise ONLY when all ten areas are
     * enterprise_ready. One unproven area drops the verdict to below_enterprise.
     */
    public function test_superation_requires_all_ten_areas_ready(): void
    {
        $service = $this->service();

        $allReady = [];
        foreach (Checklist::AREAS as $name) {
            $allReady[$name] = [
                Checklist::LEVEL_BASELINE => true,
                Checklist::LEVEL_ATLAS_PLUS => true,
                Checklist::LEVEL_PROOF => true,
            ];
        }

        $reached = $service->evaluateSuperation($allReady, []);
        $this->assertSame(Checklist::SUPERATION_REACHED, $reached['verdict']);
        $this->assertTrue($reached['is_atlas_enterprise']);
        $this->assertSame(10, $reached['ready_count']);
        $this->assertSame([], $reached['areas_not_ready']);

        // Break exactly one area (drop its proof) -> below_enterprise.
        $oneMissing = $allReady;
        $oneMissing['memory_governance'][Checklist::LEVEL_PROOF] = false;

        $below = $service->evaluateSuperation($oneMissing, []);
        $this->assertSame(Checklist::SUPERATION_BELOW, $below['verdict']);
        $this->assertFalse($below['is_atlas_enterprise']);
        $this->assertSame(9, $below['ready_count']);
        $this->assertSame(['memory_governance'], $below['areas_not_ready']);
    }

    /**
     * Superation Rule pillars: skipping evidence / review / privacy / replay makes
     * it "fast but not Atlas enterprise" EVEN when all ten areas are ready.
     */
    public function test_superation_fails_when_a_non_skippable_pillar_is_skipped(): void
    {
        $service = $this->service();

        $allReady = [];
        foreach (Checklist::AREAS as $name) {
            $allReady[$name] = [
                Checklist::LEVEL_BASELINE => true,
                Checklist::LEVEL_ATLAS_PLUS => true,
                Checklist::LEVEL_PROOF => true,
            ];
        }

        $result = $service->evaluateSuperation($allReady, ['replay' => true]);

        $this->assertTrue($result['all_areas_ready']);
        $this->assertFalse($result['pillars_honoured']);
        $this->assertSame(Checklist::SUPERATION_FAST_NOT_ENTERPRISE, $result['verdict']);
        $this->assertFalse($result['is_atlas_enterprise']);
        $this->assertSame(['replay'], $result['skipped_pillars']);
    }

    /**
     * Missing areas in the input are treated as below-baseline (not silently
     * ready): an empty input can never reach enterprise.
     */
    public function test_empty_input_is_not_enterprise(): void
    {
        $service = $this->service();

        $result = $service->evaluateSuperation([], []);

        $this->assertFalse($result['is_atlas_enterprise']);
        $this->assertSame(0, $result['ready_count']);
        $this->assertCount(10, $result['areas_not_ready']);
        $this->assertSame(Checklist::SUPERATION_BELOW, $result['verdict']);
    }
}
