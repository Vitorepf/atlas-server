<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionFinalOrganMap;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionFinalOrganMapCircuitGapsTest extends TestCase
{
    private function map(): AtlasSelfConstructionFinalOrganMap
    {
        return new AtlasSelfConstructionFinalOrganMap();
    }

    public function test_broken_brain_to_knowledge_sync_circuit_reports_gap(): void
    {
        $result = $this->map()->circuitReport([
            'implemented' => [
                AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC,
            ],
            'wired' => [
                AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC,
            ],
        ]);

        $names = array_column($result['circuit_gaps'], 'circuit');
        $this->assertContains('brain_to_knowledge_sync', $names);

        $gap = current(array_filter($result['circuit_gaps'], static fn (array $g): bool => $g['circuit'] === 'brain_to_knowledge_sync'));
        $this->assertSame(AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC, $gap['from']);
        $this->assertSame(AtlasSelfConstructionFinalOrganMap::ORGAN_DOCS_KNOWLEDGE_SYNC, $gap['to']);
        $this->assertSame('to_organ_not_implemented_or_not_wired', $gap['reason']);
    }

    public function test_closed_brain_to_knowledge_sync_circuit_reports_no_gap_for_that_circuit(): void
    {
        $result = $this->map()->circuitReport([
            'implemented' => [
                AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC,
                AtlasSelfConstructionFinalOrganMap::ORGAN_DOCS_KNOWLEDGE_SYNC,
            ],
            'wired' => [
                AtlasSelfConstructionFinalOrganMap::ORGAN_TASK_FABRIC,
                AtlasSelfConstructionFinalOrganMap::ORGAN_DOCS_KNOWLEDGE_SYNC,
            ],
        ]);

        $names = array_column($result['circuit_gaps'], 'circuit');
        $this->assertNotContains('brain_to_knowledge_sync', $names);
    }

    public function test_duplicate_low_value_organs_reported_as_consolidation_candidates(): void
    {
        $result = $this->map()->circuitReport([
            'implemented' => [],
            'wired' => [],
            'duplicate_low_value_organs' => [
                ['organ_id' => 'maestro_legacy_router', 'duplicate_of' => AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO, 'value_score' => 0.1],
            ],
        ]);

        $this->assertCount(1, $result['consolidation_candidates']);
        $this->assertSame('maestro_legacy_router', $result['consolidation_candidates'][0]['organ_id']);
        $this->assertSame(AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO, $result['consolidation_candidates'][0]['duplicate_of']);
    }

    public function test_consolidation_candidates_not_counted_as_useful_backlog(): void
    {
        $facts = [
            'implemented' => [],
            'wired' => [],
            'duplicate_low_value_organs' => [
                ['organ_id' => 'maestro_legacy_router', 'duplicate_of' => AtlasSelfConstructionFinalOrganMap::ORGAN_MAESTRO],
            ],
        ];
        $coverage = $this->map()->coverageView($facts);
        $report = $this->map()->circuitReport($facts);

        $this->assertNotContains('maestro_legacy_router', $coverage['missing_organs']);
        $this->assertNotEmpty($report['consolidation_candidates']);
    }

    public function test_no_consolidation_candidates_when_none_supplied(): void
    {
        $result = $this->map()->circuitReport(['implemented' => [], 'wired' => []]);

        $this->assertSame([], $result['consolidation_candidates']);
    }
}
