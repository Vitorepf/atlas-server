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
}
