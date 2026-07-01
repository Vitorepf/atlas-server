<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDocumentationDriftRepairPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDocumentationDriftRepairPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainDocumentationDriftRepairPlanner
    {
        return new AtlasExternalBrainDocumentationDriftRepairPlanner;
    }

    public function test_doc_repair_actions_case(): void
    {
        $r = $this->planner()->plan([
            'architecture_docs_stale' => true,
            'command_docs_stale' => true,
            'knowledge_map_stale' => true,
            'repair_actions_taken' => ['repair_architecture_docs', 'repair_command_docs', 'repair_knowledge_map'],
        ]);

        $this->assertSame('complete', $r['decision']);
        $this->assertSame(
            ['repair_architecture_docs', 'repair_command_docs', 'repair_knowledge_map'],
            $r['required_repair_actions'],
        );
        $this->assertSame([], $r['missing_repair_actions']);
    }

    public function test_missing_public_contract_doc_hold_case(): void
    {
        $r = $this->planner()->plan([
            'public_contract_changed' => true,
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('repair_contract_docs', $r['required_repair_actions']);
        $this->assertContains('repair_contract_docs', $r['missing_repair_actions']);
    }

    public function test_public_contract_changed_satisfied_by_matching_repair(): void
    {
        $r = $this->planner()->plan([
            'public_contract_changed' => true,
            'repair_actions_taken' => ['repair_contract_docs'],
        ]);

        $this->assertSame('complete', $r['decision']);
    }

    public function test_no_stale_surfaces_completes_with_no_required_actions(): void
    {
        $r = $this->planner()->plan([]);

        $this->assertSame('complete', $r['decision']);
        $this->assertSame([], $r['required_repair_actions']);
    }

    public function test_schema_present(): void
    {
        $r = $this->planner()->plan([]);

        $this->assertSame(AtlasExternalBrainDocumentationDriftRepairPlanner::SCHEMA, $r['schema']);
    }
}
