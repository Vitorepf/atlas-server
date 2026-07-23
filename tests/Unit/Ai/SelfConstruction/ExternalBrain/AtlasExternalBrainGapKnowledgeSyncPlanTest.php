<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGapKnowledgeSyncPlan;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGapKnowledgeSyncPlanTest extends TestCase
{
    private function svc(): AtlasExternalBrainGapKnowledgeSyncPlan
    {
        return new AtlasExternalBrainGapKnowledgeSyncPlan;
    }

    private function completeGap(array $overrides = []): array
    {
        return array_merge([
            'gap_id'                  => 'gap-1',
            'docs_update'             => 'docs/engineering-knowledge-base/gap-1.md updated',
            'memory_update'           => 'memory-record:gap-1-closed',
            'code_index_update'       => 'reindex:AtlasExternalBrainGapKnowledgeSyncPlan',
            'prompt_contract_update'  => 'prompt-contract:closed-gap-1',
        ], $overrides);
    }

    // ── missing required actions ──────────────────────────────────────────────

    public function test_gap_missing_docs_update_is_sync_incomplete(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['docs_update' => ''])]);

        $gap = $result['gaps'][0];
        $this->assertFalse($gap['sync_ready']);
        $this->assertContains('docs_update', $gap['missing_updates']);
    }

    public function test_gap_missing_memory_update_is_sync_incomplete(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['memory_update' => null])]);

        $gap = $result['gaps'][0];
        $this->assertFalse($gap['sync_ready']);
        $this->assertContains('memory_update', $gap['missing_updates']);
    }

    public function test_gap_missing_code_index_update_is_sync_incomplete(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['code_index_update' => []])]);

        $gap = $result['gaps'][0];
        $this->assertFalse($gap['sync_ready']);
        $this->assertContains('code_index_update', $gap['missing_updates']);
    }

    public function test_gap_missing_prompt_contract_update_is_sync_incomplete(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['prompt_contract_update' => '   '])]);

        $gap = $result['gaps'][0];
        $this->assertFalse($gap['sync_ready']);
        $this->assertContains('prompt_contract_update', $gap['missing_updates']);
    }

    public function test_all_sync_ready_is_false_when_any_gap_incomplete(): void
    {
        $result = $this->svc()->plan([
            $this->completeGap(['gap_id' => 'good']),
            $this->completeGap(['gap_id' => 'bad', 'docs_update' => '']),
        ]);

        $this->assertFalse($result['all_sync_ready']);
    }

    // ── complete plan ──────────────────────────────────────────────────────────

    public function test_complete_plan_returns_sync_ready_true(): void
    {
        $result = $this->svc()->plan([$this->completeGap()]);

        $gap = $result['gaps'][0];
        $this->assertTrue($gap['sync_ready']);
        $this->assertSame([], $gap['missing_updates']);
        $this->assertTrue($result['all_sync_ready']);
    }

    public function test_complete_plan_has_stable_update_hashes(): void
    {
        $svc = $this->svc();
        $gap = $this->completeGap();

        $first = $svc->plan([$gap])['gaps'][0]['update_hashes'];
        $second = $svc->plan([$gap])['gaps'][0]['update_hashes'];

        $this->assertSame($first, $second);
        foreach (AtlasExternalBrainGapKnowledgeSyncPlan::REQUIRED_ACTIONS as $action) {
            $this->assertArrayHasKey($action, $first);
            $this->assertNotNull($first[$action]);
        }
    }

    public function test_changing_update_content_changes_its_hash(): void
    {
        $svc = $this->svc();
        $a = $svc->plan([$this->completeGap(['docs_update' => 'version A'])])['gaps'][0]['update_hashes']['docs_update'];
        $b = $svc->plan([$this->completeGap(['docs_update' => 'version B'])])['gaps'][0]['update_hashes']['docs_update'];

        $this->assertNotSame($a, $b);
    }

    public function test_missing_action_has_null_hash(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['memory_update' => ''])]);

        $this->assertNull($result['gaps'][0]['update_hashes']['memory_update']);
    }

    // ── optional notes vs required actions ────────────────────────────────────

    public function test_notes_are_optional_and_do_not_satisfy_required_actions(): void
    {
        $result = $this->svc()->plan([$this->completeGap([
            'docs_update' => '',
            'notes' => ['considered updating docs but decided not to'],
        ])]);

        $gap = $result['gaps'][0];
        $this->assertFalse($gap['sync_ready']);
        $this->assertContains('docs_update', $gap['missing_updates']);
        $this->assertContains('considered updating docs but decided not to', $gap['notes']);
    }

    public function test_notes_field_is_distinct_from_missing_updates(): void
    {
        $result = $this->svc()->plan([$this->completeGap(['notes' => ['fyi: also touched adjacent module']])]);

        $gap = $result['gaps'][0];
        $this->assertTrue($gap['sync_ready']);
        $this->assertSame(['fyi: also touched adjacent module'], $gap['notes']);
    }

    public function test_no_notes_yields_empty_notes_list(): void
    {
        $result = $this->svc()->plan([$this->completeGap()]);

        $this->assertSame([], $result['gaps'][0]['notes']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.gap_knowledge_sync_plan.v1', AtlasExternalBrainGapKnowledgeSyncPlan::SCHEMA);
    }

    public function test_empty_gaps_list_is_not_sync_ready(): void
    {
        $result = $this->svc()->plan([]);

        $this->assertSame([], $result['gaps']);
        $this->assertFalse($result['all_sync_ready']);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $gaps = [$this->completeGap(), $this->completeGap(['gap_id' => 'gap-2', 'docs_update' => ''])];

        $this->assertSame(json_encode($svc->plan($gaps)), json_encode($svc->plan($gaps)));
    }
}
