<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDocsMemorySyncVerifier;
use Tests\TestCase;

final class AtlasExternalBrainDocsMemorySyncVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainDocsMemorySyncVerifier
    {
        return new AtlasExternalBrainDocsMemorySyncVerifier;
    }

    public function test_behavior_changed_with_missing_docs_and_memory_returns_knowledge_stale_with_smallest_required_actions(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_KNOWLEDGE_STALE, $result['sync_status']);
        $this->assertSame(['docs_patch', 'memory_outcome_record'], $result['required_actions']);
        $this->assertSame(['docs', 'memory'], $result['stale_surfaces']);
    }

    public function test_behavior_changed_with_only_docs_missing_returns_only_docs_patch(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => true,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_KNOWLEDGE_STALE, $result['sync_status']);
        $this->assertSame(['docs_patch'], $result['required_actions']);
        $this->assertSame(['docs'], $result['stale_surfaces']);
    }

    public function test_stale_code_index_returns_index_code_refresh_without_blaming_docs_when_docs_and_memory_current(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => true,
            'docs_updated' => true,
            'memory_facts_recorded' => true,
            'code_index_fresh' => false,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $result['sync_status']);
        $this->assertSame(['index_code_refresh'], $result['required_actions']);
        $this->assertSame(['code_index'], $result['stale_surfaces']);
        $this->assertNotContains('docs', $result['stale_surfaces']);
        $this->assertNotContains('memory', $result['stale_surfaces']);
    }

    public function test_test_only_change_returns_no_action_unless_code_index_stale(): void
    {
        $fresh = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_test_only' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);
        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $fresh['sync_status']);
        $this->assertSame([], $fresh['required_actions']);

        $staleIndex = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_test_only' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => false,
        ]);
        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $staleIndex['sync_status']);
        $this->assertSame(['index_code_refresh'], $staleIndex['required_actions']);
    }

    public function test_pure_internal_refactor_returns_no_action_unless_code_index_stale(): void
    {
        $fresh = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);
        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $fresh['sync_status']);
        $this->assertSame([], $fresh['required_actions']);

        $staleIndex = $this->verifier()->verify([
            'behavior_changed' => true,
            'is_internal_refactor' => true,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => false,
        ]);
        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_INDEX_STALE, $staleIndex['sync_status']);
        $this->assertSame(['index_code_refresh'], $staleIndex['required_actions']);
    }

    public function test_no_behavior_change_and_fresh_index_is_no_action(): void
    {
        $result = $this->verifier()->verify([
            'behavior_changed' => false,
            'docs_updated' => false,
            'memory_facts_recorded' => false,
            'code_index_fresh' => true,
        ]);

        $this->assertSame(AtlasExternalBrainDocsMemorySyncVerifier::STATUS_NO_ACTION, $result['sync_status']);
        $this->assertSame([], $result['required_actions']);
    }
}
