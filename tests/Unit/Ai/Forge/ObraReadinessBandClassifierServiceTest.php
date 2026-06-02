<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Forge;

use App\Services\Ai\Forge\ObraReadinessBandClassifierService;
use PHPUnit\Framework\TestCase;

final class ObraReadinessBandClassifierServiceTest extends TestCase
{
    private ObraReadinessBandClassifierService $classifier;

    /**
     * The 12 doctrine gate keys in canonical order.
     *
     * @var list<string>
     */
    private const ALL_GATES = [
        'objective',
        'definition_of_done',
        'structure',
        'next_step',
        'decisions',
        'evidence_refs',
        'risks',
        'tradeoffs',
        'current_version',
        'output_intent',
        'learning',
        'parent_objective',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ObraReadinessBandClassifierService();
    }

    public function test_return_shape_matches_schema(): void
    {
        $result = $this->classifier->classify($this->gates([]));

        self::assertSame('atlas.obra.readiness_band.v1', $result['schema_version']);
        self::assertArrayHasKey('band', $result);
        self::assertArrayHasKey('gates_passed', $result);
        self::assertArrayHasKey('next_band', $result);
        self::assertArrayHasKey('blocking_requirement', $result);
        self::assertSame(0, $result['gates_passed']);
    }

    public function test_rule_one_objective_not_passed_is_not_an_obra(): void
    {
        // Every gate except the objective is passed; band must still bottom out.
        $passed = self::ALL_GATES;
        unset($passed[0]); // drop 'objective'

        $result = $this->classifier->classify($this->gates(array_values($passed)));

        self::assertSame('not_an_obra', $result['band']);
        self::assertNotNull($result['blocking_requirement']);
        self::assertStringContainsString('objective', $result['blocking_requirement']);
        self::assertSame(11, $result['gates_passed']);
    }

    public function test_rule_two_objective_passed_next_step_missing_is_idea(): void
    {
        $result = $this->classifier->classify($this->gates([
            'objective',
            'definition_of_done',
            'structure',
        ]));

        self::assertSame('idea', $result['band']);
        self::assertSame('intake_ready', $result['next_band']);
        self::assertSame(3, $result['gates_passed']);
    }

    public function test_rule_three_intake_ready_names_first_missing_foundation_gate(): void
    {
        // definition_of_done missing while structure passes => first missing is definition_of_done.
        $resultDod = $this->classifier->classify($this->gates([
            'objective',
            'next_step',
            'structure',
        ]));

        self::assertSame('intake_ready', $resultDod['band']);
        self::assertSame('definition_of_done', $resultDod['blocking_requirement']);

        // definition_of_done passes but structure missing => first missing is structure.
        $resultStructure = $this->classifier->classify($this->gates([
            'objective',
            'next_step',
            'definition_of_done',
        ]));

        self::assertSame('intake_ready', $resultStructure['band']);
        self::assertSame('structure', $resultStructure['blocking_requirement']);
    }

    public function test_rule_four_construction_ready_when_a_remaining_gate_is_unmet(): void
    {
        // Foundation gates all pass, plus several remaining gates, but not all.
        $result = $this->classifier->classify($this->gates([
            'objective',
            'next_step',
            'definition_of_done',
            'structure',
            'decisions',
            'evidence_refs',
            'risks',
        ]));

        self::assertSame('construction_ready', $result['band']);
        self::assertSame('review_ready', $result['next_band']);
        self::assertSame('tradeoffs', $result['blocking_requirement']);
        self::assertSame(7, $result['gates_passed']);
    }

    public function test_rule_five_all_gates_passed_is_review_ready(): void
    {
        $result = $this->classifier->classify($this->gates(self::ALL_GATES));

        self::assertSame('review_ready', $result['band']);
        self::assertNull($result['next_band']);
        self::assertNull($result['blocking_requirement']);
        self::assertSame(12, $result['gates_passed']);
    }

    public function test_band_never_regresses_for_a_superset_of_passed_gates(): void
    {
        $rank = [
            'not_an_obra' => 0,
            'idea' => 1,
            'intake_ready' => 2,
            'construction_ready' => 3,
            'review_ready' => 4,
        ];

        $passed = [];
        $previousRank = -1;
        $previousCount = -1;

        // Add gates one at a time in canonical order. Each step is a strict
        // superset of the prior, so the band rank must be non-decreasing and
        // gates_passed must strictly increase.
        foreach (self::ALL_GATES as $gate) {
            $passed[] = $gate;
            $result = $this->classifier->classify($this->gates($passed));

            $currentRank = $rank[$result['band']];
            self::assertGreaterThanOrEqual(
                $previousRank,
                $currentRank,
                'band regressed when gate '.$gate.' was added',
            );
            self::assertGreaterThan($previousCount, $result['gates_passed']);

            $previousRank = $currentRank;
            $previousCount = $result['gates_passed'];
        }

        // The fully-passed superset is the maximum band.
        self::assertSame(4, $previousRank);
        self::assertSame(12, $previousCount);
    }

    public function test_classification_is_deterministic(): void
    {
        $input = $this->gates([
            'objective',
            'next_step',
            'definition_of_done',
            'structure',
            'output_intent',
        ]);

        self::assertSame(
            $this->classifier->classify($input),
            $this->classifier->classify($input),
        );
    }

    /**
     * Build a gate-scorer verdict where the named gates are passed (true) and
     * every other doctrine gate is failed (false).
     *
     * @param  list<string>  $passedGates
     * @return array<string, bool>
     */
    private function gates(array $passedGates): array
    {
        $verdict = [];
        foreach (self::ALL_GATES as $gate) {
            $verdict[$gate] = in_array($gate, $passedGates, true);
        }

        return $verdict;
    }
}
