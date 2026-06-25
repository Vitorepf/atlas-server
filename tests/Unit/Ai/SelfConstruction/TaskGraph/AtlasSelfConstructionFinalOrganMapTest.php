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
}
