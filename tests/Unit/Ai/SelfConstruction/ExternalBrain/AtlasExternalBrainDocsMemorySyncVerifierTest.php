<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDocsMemorySyncVerifier;
use Tests\TestCase;

final class AtlasExternalBrainDocsMemorySyncVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainDocsMemorySyncVerifier
    {
        return new AtlasExternalBrainDocsMemorySyncVerifier;
    }

    public function test_behavior_change_without_docs_or_memory_is_flagged_knowledge_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_KNOWLEDGE_STALE, $result['sync_status']);
        $this->assertContains('docs', $result['stale_surfaces']);
        $this->assertContains('memory', $result['stale_surfaces']);
        $this->assertContains('docs_patch', $result['required_actions']);
        $this->assertContains('memory_outcome_record', $result['required_actions']);
    }

    public function test_test_only_change_returns_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_test_only' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
        $this->assertSame([], $result['stale_surfaces']);
    }

    public function test_internal_refactor_returns_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
    }

    public function test_no_behavior_change_returns_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
    }

    public function test_missing_code_index_freshness_recommends_refresh_without_flagging_docs_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'code_index_fresh' => false,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $result['sync_status']);
        $this->assertContains('index_code_refresh', $result['required_actions']);
        $this->assertContains('code_index', $result['stale_surfaces']);
        $this->assertNotContains('docs', $result['stale_surfaces']);
        $this->assertNotContains('docs_patch', $result['required_actions']);
    }

    public function test_behavior_change_with_docs_and_memory_present_is_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
    }

    public function test_output_includes_all_four_required_fields(): void
    {
        $result = $this->verifier()->verify(['behavior_changed' => false]);

        $this->assertArrayHasKey('sync_status', $result);
        $this->assertArrayHasKey('required_actions', $result);
        $this->assertArrayHasKey('stale_surfaces', $result);
        $this->assertArrayHasKey('evidence', $result);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $verifier = $this->verifier();
        $change = ['behavior_changed' => true, 'docs_updated' => false, 'memory_facts_recorded' => false, 'code_index_fresh' => false];

        $this->assertSame($verifier->verify($change), $verifier->verify($change));
    }

    public function test_test_only_change_with_stale_index_still_returns_index_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_test_only' => true,
            'code_index_fresh' => false,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $result['sync_status']);
        $this->assertContains('index_code_refresh', $result['required_actions']);
    }

    public function test_internal_refactor_with_stale_index_still_returns_index_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'code_index_fresh' => false,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $result['sync_status']);
    }

    // ── new AC: public_contract_changed forces sync even on internal refactor ──

    public function test_public_contract_changed_forces_knowledge_stale_despite_internal_refactor(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'public_contract_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_KNOWLEDGE_STALE, $result['sync_status']);
        $this->assertContains('docs', $result['stale_surfaces']);
        $this->assertContains('memory', $result['stale_surfaces']);
    }

    public function test_internal_refactor_without_public_contract_change_keeps_no_action_when_index_fresh(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'public_contract_changed' => false,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
    }

    public function test_code_index_stale_reported_independently_of_docs_or_memory_staleness(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'public_contract_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'code_index_fresh' => false,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $result['sync_status']);
        $this->assertContains('code_index', $result['stale_surfaces']);
        $this->assertNotContains('docs', $result['stale_surfaces']);
        $this->assertNotContains('memory', $result['stale_surfaces']);
    }

    // ── AC1/AC2: fresh_context, missing_artifacts, stale_artifacts, sync_command_hints ──

    public function test_fresh_sync_yields_fresh_context_true_and_empty_artifact_lists(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'code_index_fresh' => true,
        ]);

        $this->assertTrue($result['fresh_context']);
        $this->assertSame([], $result['missing_artifacts']);
        $this->assertSame([], $result['stale_artifacts']);
        $this->assertSame([], $result['sync_command_hints']);
    }

    public function test_missing_docs_blocks_fresh_context_and_reports_missing_artifact(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => true,
            'code_index_fresh' => true,
        ]);

        $this->assertFalse($result['fresh_context']);
        $this->assertContains('docs', $result['missing_artifacts']);
        $this->assertNotEmpty($result['sync_command_hints']);
    }

    public function test_stale_memory_by_timestamp_blocks_fresh_context_and_reports_stale_artifact(): void
    {
        $now = 1_700_100_000;
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'memory_recorded_at' => $now - 200_000,
            'code_index_fresh' => true,
            'now_unix' => $now,
            'max_artifact_age_seconds' => 86_400,
        ]);

        $this->assertFalse($result['fresh_context']);
        $this->assertContains('memory', $result['stale_artifacts']);
        $this->assertNotContains('memory', $result['missing_artifacts']);
    }

    public function test_recent_memory_timestamp_within_freshness_window_is_fresh(): void
    {
        $now = 1_700_100_000;
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'memory_recorded_at' => $now - 100,
            'code_index_fresh' => true,
            'now_unix' => $now,
            'max_artifact_age_seconds' => 86_400,
        ]);

        $this->assertTrue($result['fresh_context']);
        $this->assertSame([], $result['stale_artifacts']);
    }

    public function test_missing_code_index_blocks_fresh_context_and_reports_missing_artifact(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'code_index_fresh' => false,
        ]);

        $this->assertFalse($result['fresh_context']);
        $this->assertContains('code_index', $result['missing_artifacts']);
        $this->assertNotEmpty($result['sync_command_hints']);
    }

    public function test_no_sync_needed_cosmetic_change_is_fresh_context_true(): void
    {
        // A cosmetic change (no behavior change, no test-only/internal flag needed) with an
        // already-fresh code index needs no follow-up at all.
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertTrue($result['fresh_context']);
        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
        $this->assertSame([], $result['missing_artifacts']);
        $this->assertSame([], $result['stale_artifacts']);
        $this->assertSame([], $result['sync_command_hints']);
    }

    // ── smallest_follow_up ──

    public function test_smallest_follow_up_present_in_output(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertArrayHasKey('smallest_follow_up', $result);
        $this->assertSame('docs_patch', $result['smallest_follow_up']);
    }

    public function test_smallest_follow_up_no_follow_up_when_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame('no_follow_up_needed', $result['smallest_follow_up']);
    }

    public function test_smallest_follow_up_index_refresh_when_only_index_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'code_index_fresh' => false,
        ]);

        $this->assertSame('index_code_refresh', $result['smallest_follow_up']);
    }

    public function test_smallest_follow_up_picks_cheapest_when_multiple_stale(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => false,
        ]);

        // index_code_refresh is cheapest (priority 1)
        $this->assertSame('index_code_refresh', $result['smallest_follow_up']);
    }
}
