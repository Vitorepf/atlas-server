<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationKnowledgeSyncPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationKnowledgeSyncPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainSimplificationKnowledgeSyncPlanner
    {
        return new AtlasExternalBrainSimplificationKnowledgeSyncPlanner;
    }

    public function test_required_sync_case_completes_when_all_taken(): void
    {
        $r = $this->planner()->plan([
            'docs_changed' => true,
            'memory_entries_affected' => ['brain-external-loop.md'],
            'sync_actions_taken' => ['sync_docs', 'sync_memory'],
        ]);

        $this->assertSame('complete', $r['decision']);
        $this->assertSame(['sync_docs', 'sync_memory'], $r['required_sync_actions']);
        $this->assertSame([], $r['missing_sync_actions']);
    }

    public function test_missing_sync_hold_case_public_behavior_changed_no_actions(): void
    {
        $r = $this->planner()->plan([
            'public_behavior_changed' => true,
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('sync_docs', $r['required_sync_actions']);
        $this->assertContains('sync_code_index', $r['required_sync_actions']);
        $this->assertContains('sync_docs', $r['missing_sync_actions']);
        $this->assertContains('sync_code_index', $r['missing_sync_actions']);
    }

    public function test_architecture_labels_changed_requires_domain_map_sync(): void
    {
        $r = $this->planner()->plan([
            'architecture_labels_changed' => true,
            'sync_actions_taken' => ['sync_docs', 'sync_code_index'],
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('sync_domain_map', $r['missing_sync_actions']);
    }

    public function test_code_index_stale_requires_sync_code_index(): void
    {
        $r = $this->planner()->plan([
            'code_index_stale' => true,
            'sync_actions_taken' => ['sync_code_index'],
        ]);

        $this->assertSame('complete', $r['decision']);
        $this->assertSame(['sync_code_index'], $r['required_sync_actions']);
    }

    public function test_domain_map_affected_requires_sync_domain_map(): void
    {
        $r = $this->planner()->plan([
            'domain_map_affected' => ['finance'],
            'sync_actions_taken' => [],
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('sync_domain_map', $r['missing_sync_actions']);
    }

    public function test_no_changed_surfaces_completes_with_no_required_actions(): void
    {
        $r = $this->planner()->plan([]);

        $this->assertSame('complete', $r['decision']);
        $this->assertSame([], $r['required_sync_actions']);
        $this->assertSame([], $r['missing_sync_actions']);
    }

    public function test_schema_present(): void
    {
        $r = $this->planner()->plan([]);

        $this->assertSame(AtlasExternalBrainSimplificationKnowledgeSyncPlanner::SCHEMA, $r['schema']);
    }
}
