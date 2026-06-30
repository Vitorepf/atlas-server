<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionFinalOrganMap;
use Tests\TestCase;

class AtlasSelfConstructionFinalOrganMapTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_IDS = [
        AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX,
        AtlasSelfConstructionFinalOrganMap::ORGAN_GOAL_AND_VALUE,
        AtlasSelfConstructionFinalOrganMap::ORGAN_STRATEGY_COUNCIL,
        AtlasSelfConstructionFinalOrganMap::ORGAN_ARCHITECTURE_COUNCIL,
        AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC,
        AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO,
        AtlasSelfConstructionFinalOrganMap::ORGAN_WORKER_SWARM,
        AtlasSelfConstructionFinalOrganMap::ORGAN_VERIFICATION_COURT,
        AtlasSelfConstructionFinalOrganMap::ORGAN_MERGE_GOVERNOR,
        AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS,
        AtlasSelfConstructionFinalOrganMap::ORGAN_LEARNING_TRANSFER,
        AtlasSelfConstructionFinalOrganMap::ORGAN_AUTOPOIESIS_LOOP,
        AtlasSelfConstructionFinalOrganMap::ORGAN_DOCS_KNOWLEDGE_SYNC,
        AtlasSelfConstructionFinalOrganMap::ORGAN_OPERATOR_VISIBILITY,
        AtlasSelfConstructionFinalOrganMap::ORGAN_MULTI_PROJECT_STEWARDSHIP,
        AtlasSelfConstructionFinalOrganMap::ORGAN_CODE_INTELLIGENCE,
        AtlasSelfConstructionFinalOrganMap::ORGAN_FINAL_COMPLETION,
    ];

    public function test_all_required_organ_ids_are_present(): void
    {
        $map = (new AtlasSelfConstructionFinalOrganMap)->describe();

        $ids = array_column($map['organs'], 'organ_id');
        foreach (self::REQUIRED_IDS as $required) {
            self::assertContains($required, $ids, "organ {$required} must be present");
        }
        self::assertCount(count(self::REQUIRED_IDS), $map['organs']);
    }

    public function test_organ_ids_are_unique(): void
    {
        $map = (new AtlasSelfConstructionFinalOrganMap)->describe();
        $ids = array_column($map['organs'], 'organ_id');

        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_each_organ_carries_purpose_capabilities_evidence_and_non_authorities(): void
    {
        $map = (new AtlasSelfConstructionFinalOrganMap)->describe();

        foreach ($map['organs'] as $organ) {
            self::assertNotEmpty($organ['organ_id']);
            self::assertNotEmpty($organ['purpose'], "{$organ['organ_id']} must have a purpose");
            self::assertNotEmpty($organ['required_task_tags'], "{$organ['organ_id']} must have required_task_tags");
            self::assertNotEmpty($organ['required_capabilities'], "{$organ['organ_id']} must have required_capabilities");
            self::assertNotEmpty($organ['blocking_evidence_ids'], "{$organ['organ_id']} must have blocking_evidence_ids");
            self::assertNotEmpty($organ['non_authorities'], "{$organ['organ_id']} must have non_authorities");
        }
    }

    public function test_aggregate_evidence_expectations_and_non_authorities_are_present(): void
    {
        $map = (new AtlasSelfConstructionFinalOrganMap)->describe();

        self::assertNotEmpty($map['evidence_expectations']);
        self::assertNotEmpty($map['non_authorities']);
        self::assertContains('must_not_widen_scope', $map['non_authorities']);
        self::assertContains('tests_or_gates_result', $map['evidence_expectations']);
    }

    public function test_output_is_deterministic_across_two_invocations(): void
    {
        $a = (new AtlasSelfConstructionFinalOrganMap)->describe();
        $b = (new AtlasSelfConstructionFinalOrganMap)->describe();

        self::assertSame($a, $b);
        self::assertArrayNotHasKey('score', $a);
    }

    // ---------- coverageView ----------

    public function test_coverage_view_no_organs_implemented_gives_zero_pct_and_all_missing(): void
    {
        $map = new AtlasSelfConstructionFinalOrganMap;
        $view = $map->coverageView([]);

        self::assertSame(0.0, $view['completion_pct']);
        self::assertSame([], $view['implemented_organs']);
        self::assertCount($view['total_organs'], $view['missing_organs']);
        self::assertSame([], $view['missing_tests']);
        self::assertSame([], $view['unwired_organs']);
    }

    public function test_coverage_view_implemented_organs_not_in_missing(): void
    {
        $map  = new AtlasSelfConstructionFinalOrganMap;
        $view = $map->coverageView([
            'implemented' => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS],
            'has_tests'   => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS],
            'wired'       => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS],
        ]);

        self::assertContains(AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX,   $view['implemented_organs']);
        self::assertContains(AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS, $view['implemented_organs']);
        self::assertNotContains(AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX,   $view['missing_organs']);
        self::assertNotContains(AtlasSelfConstructionFinalOrganMap::ORGAN_RECEIPTS, $view['missing_organs']);
        self::assertSame([], $view['missing_tests']);
        self::assertSame([], $view['unwired_organs']);
    }

    public function test_coverage_view_missing_tests_when_implemented_but_no_test(): void
    {
        $map  = new AtlasSelfConstructionFinalOrganMap;
        $view = $map->coverageView([
            'implemented' => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO],
            'has_tests'   => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX],
            'wired'       => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO],
        ]);

        self::assertContains(AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO, $view['missing_tests']);
        self::assertNotContains(AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, $view['missing_tests']);
    }

    public function test_coverage_view_unwired_organs_when_implemented_but_not_wired(): void
    {
        $map  = new AtlasSelfConstructionFinalOrganMap;
        $view = $map->coverageView([
            'implemented' => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC],
            'has_tests'   => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC],
            'wired'       => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX],
        ]);

        self::assertContains(AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC, $view['unwired_organs']);
        self::assertNotContains(AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX,   $view['unwired_organs']);
    }

    public function test_coverage_view_completion_pct_is_implemented_over_total(): void
    {
        $map   = new AtlasSelfConstructionFinalOrganMap;
        $total = count($map->organs());

        $view = $map->coverageView([
            'implemented' => array_column($map->organs(), 'organ_id'),
            'has_tests'   => array_column($map->organs(), 'organ_id'),
            'wired'       => array_column($map->organs(), 'organ_id'),
        ]);

        self::assertSame(100.0, $view['completion_pct']);
        self::assertSame($total, $view['total_organs']);
        self::assertSame([], $view['missing_organs']);
    }

    public function test_ranked_gaps_missing_organs_outrank_untested_and_unwired(): void
    {
        $map = new AtlasSelfConstructionFinalOrganMap;

        // cortex: implemented+tested, NOT wired → rank 3 (unwired)
        // maestro: implemented, NOT tested → rank 2 (untested)
        // all others: not implemented → rank 1 (missing)
        $gaps = $map->rankedGaps([
            'implemented' => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX, AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO],
            'has_tests'   => [AtlasSelfConstructionFinalOrganMap::ORGAN_CORTEX],
            'wired'       => [],
        ]);

        self::assertNotEmpty($gaps);
        self::assertSame('missing', $gaps[0]['gap_type'], 'first gap must be missing (rank 1)');

        $types = array_column($gaps, 'gap_type');
        self::assertContains('missing', $types);
        self::assertContains('untested', $types);
        self::assertContains('unwired', $types);

        // ranks must be non-decreasing
        $ranks = array_column($gaps, 'rank');
        self::assertSame($ranks, array_values($ranks));
        for ($i = 1; $i < count($ranks); $i++) {
            self::assertGreaterThanOrEqual($ranks[$i - 1], $ranks[$i]);
        }
    }
}
