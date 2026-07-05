<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncCodeIndexReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasKnowledgeSyncCodeIndexReadinessGateTest extends TestCase
{
    private AtlasKnowledgeSyncCodeIndexReadinessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasKnowledgeSyncCodeIndexReadinessGate();
    }

    // AC: when changed_code_hash present, missing/mismatched symbol delta → blocker
    public function test_missing_symbol_delta_when_code_changed_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            // changed_symbol_delta_hash missing
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'changed_symbol_delta_hash'))) > 0
        );
    }

    public function test_symbol_delta_matches_code_hash_is_ready(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_indexed_code_hash_mismatch_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'stale_hash',
        ]);

        $this->assertFalse($result['ready']);
    }

    public function test_no_changed_code_hash_is_ready(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertTrue($result['ready']);
    }

    public function test_docs_only_bypass_is_ready(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'docs_only_bypass' => true,
        ]);

        $this->assertTrue($result['ready']);
    }

    public function test_empty_symbol_delta_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => '',
        ]);

        $this->assertFalse($result['ready']);
    }

    // ── AC: stale code index age blocks readiness with refresh_code_index action ──

    public function test_stale_code_index_age_blocks_readiness(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'code_index_age_seconds' => 7200, // > 3600 threshold
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'stale_code_index_age'))) > 0
        );
    }

    public function test_stale_code_index_emits_refresh_code_index_action(): void
    {
        $result = $this->gate->evaluate([
            'code_index_age_seconds' => 7200,
        ]);

        $this->assertContains('refresh_code_index', $result['refresh_actions']);
    }

    public function test_fresh_code_index_age_does_not_block(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'code_index_age_seconds' => 1800, // < 3600 threshold
        ]);

        $this->assertTrue($result['ready']);
    }

    // ── AC: docs sync drift blocks readiness separately from code index freshness ──

    public function test_docs_sync_drift_blocks_readiness(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'docs_sync_drift_score' => 0.50, // > 0.10 threshold
        ]);

        $this->assertFalse($result['ready']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'docs_sync_drift'))) > 0
        );
    }

    public function test_docs_sync_drift_emits_refresh_docs_sync_action(): void
    {
        $result = $this->gate->evaluate([
            'docs_sync_drift_score' => 0.50,
        ]);

        $this->assertContains('refresh_docs_sync', $result['refresh_actions']);
    }

    public function test_docs_sync_drift_blocks_independently_of_code_index(): void
    {
        // Code index is fresh and hashes match, but docs drift is high
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'code_index_age_seconds' => 60,
            'docs_sync_drift_score' => 0.50,
        ]);

        $this->assertFalse($result['ready']);
        // Only docs drift blocker, no code index blocker
        $codeIndexBlockers = array_filter($result['blockers'], fn ($b) => str_contains($b, 'stale_code_index'));
        $this->assertSame([], array_values($codeIndexBlockers));
    }

    public function test_low_docs_sync_drift_does_not_block(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'docs_sync_drift_score' => 0.05, // < 0.10 threshold
        ]);

        $this->assertTrue($result['ready']);
    }

    // ── AC: fresh index and synced docs return ready=true with provider-safe summary ──

    public function test_fresh_index_and_synced_docs_return_ready_with_summary(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
            'code_index_age_seconds' => 60,
            'docs_sync_drift_score' => 0.02,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame('code_index_and_docs_sync_ready', $result['summary']);
    }

    public function test_summary_is_provider_safe_no_raw_hashes(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'secret-hash-abc',
            'changed_symbol_delta_hash' => 'secret-delta',
            'code_index_age_seconds' => 7200,
        ]);

        $this->assertStringNotContainsString('secret-hash-abc', $result['summary']);
        $this->assertStringNotContainsString('secret-delta', $result['summary']);
    }

    public function test_refresh_actions_present_in_output(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertArrayHasKey('refresh_actions', $result);
        $this->assertIsArray($result['refresh_actions']);
    }
}
