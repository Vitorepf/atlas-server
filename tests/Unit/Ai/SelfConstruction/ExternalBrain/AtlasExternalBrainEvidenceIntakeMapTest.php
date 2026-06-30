<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceIntakeMap;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEvidenceIntakeMapTest extends TestCase
{
    private AtlasExternalBrainEvidenceIntakeMap $map;

    protected function setUp(): void
    {
        $this->map = new AtlasExternalBrainEvidenceIntakeMap;
    }

    // ── describe() shape ──────────────────────────────────────────────────────

    public function test_describe_returns_schema_streams_and_rejected_sources(): void
    {
        $d = $this->map->describe();

        $this->assertSame(AtlasExternalBrainEvidenceIntakeMap::SCHEMA, $d['schema']);
        $this->assertArrayHasKey('streams', $d);
        $this->assertArrayHasKey('rejected_sources', $d);
        $this->assertIsArray($d['streams']);
        $this->assertIsArray($d['rejected_sources']);
    }

    public function test_exactly_eight_permitted_streams_are_declared(): void
    {
        $d = $this->map->describe();
        $this->assertCount(8, $d['streams']);
    }

    public function test_all_eight_expected_stream_ids_present(): void
    {
        $ids = array_column($this->map->describe()['streams'], 'stream_id');

        foreach (['queue_health', 'queued_targets', 'muscle_outcomes', 'give_back_reasons',
                  'code_facts', 'docs_drift', 'runtime_receipts', 'research_source_plans'] as $expected) {
            $this->assertContains($expected, $ids, "stream {$expected} must be declared");
        }
    }

    public function test_each_stream_has_required_keys(): void
    {
        foreach ($this->map->describe()['streams'] as $stream) {
            foreach (['stream_id', 'source_type', 'freshness_expectation', 'minimum_fields', 'influences'] as $key) {
                $this->assertArrayHasKey($key, $stream, "stream must declare {$key}");
            }
            $this->assertIsArray($stream['minimum_fields']);
            $this->assertIsArray($stream['influences']);
            $this->assertNotEmpty($stream['minimum_fields'], 'every stream must have at least one minimum field');
            $this->assertNotEmpty($stream['influences'], 'every stream must influence at least one decision');
        }
    }

    // ── rejected sources ──────────────────────────────────────────────────────

    public function test_chat_memory_is_in_rejected_sources(): void
    {
        $this->assertContains('chat_memory', $this->map->describe()['rejected_sources']);
    }

    public function test_raw_provider_prompt_is_in_rejected_sources(): void
    {
        $this->assertContains('raw_provider_prompt', $this->map->describe()['rejected_sources']);
    }

    public function test_is_rejected_returns_true_for_chat_memory(): void
    {
        $this->assertTrue($this->map->isRejected('chat_memory'));
    }

    public function test_is_rejected_returns_true_for_raw_provider_prompt(): void
    {
        $this->assertTrue($this->map->isRejected('raw_provider_prompt'));
    }

    public function test_is_rejected_returns_false_for_permitted_stream(): void
    {
        $this->assertFalse($this->map->isRejected('queue_health'));
        $this->assertFalse($this->map->isRejected('runtime_receipts'));
    }

    // ── validate(): rejected streams fail immediately ─────────────────────────

    public function test_validate_rejects_chat_memory_regardless_of_record(): void
    {
        $r = $this->map->validate('chat_memory', ['anything' => 'goes']);

        $this->assertFalse($r['valid']);
        $this->assertTrue($r['rejected']);
        $this->assertSame('chat_memory', $r['stream_id']);
    }

    public function test_validate_rejects_raw_provider_prompt(): void
    {
        $r = $this->map->validate('raw_provider_prompt', ['depth' => 5]);

        $this->assertFalse($r['valid']);
        $this->assertTrue($r['rejected']);
    }

    // ── validate(): valid records ─────────────────────────────────────────────

    public function test_validate_passes_queue_health_with_all_minimum_fields(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 12,
            'stall_count' => 2,
            'oldest_queued_at_unix' => 1751290000,
        ]);

        $this->assertTrue($r['valid']);
        $this->assertFalse($r['rejected']);
        $this->assertSame([], $r['missing_fields']);
    }

    public function test_validate_passes_runtime_receipts_with_all_minimum_fields(): void
    {
        $r = $this->map->validate('runtime_receipts', [
            'event_type' => 'cert_passed',
            'subject_id' => 'task-01',
            'recorded_at_unix' => 1751290000,
            'payload_hash' => 'abc123',
        ]);

        $this->assertTrue($r['valid']);
        $this->assertSame([], $r['missing_fields']);
    }

    // ── validate(): missing fields ────────────────────────────────────────────

    public function test_validate_fails_queue_health_with_missing_field(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 5,
            // stall_count and oldest_queued_at_unix missing
        ]);

        $this->assertFalse($r['valid']);
        $this->assertFalse($r['rejected']);
        $this->assertContains('stall_count', $r['missing_fields']);
        $this->assertContains('oldest_queued_at_unix', $r['missing_fields']);
    }

    public function test_validate_fails_muscle_outcomes_missing_outcome_field(): void
    {
        $r = $this->map->validate('muscle_outcomes', [
            'task_packet_id' => 't1',
            'worker_id' => 'claude-1',
            'reported_at_unix' => 1751290000,
            // outcome missing
        ]);

        $this->assertFalse($r['valid']);
        $this->assertContains('outcome', $r['missing_fields']);
    }

    // ── validate(): unknown stream ────────────────────────────────────────────

    public function test_validate_fails_for_unknown_stream_id(): void
    {
        $r = $this->map->validate('nonexistent_stream', ['anything' => 1]);

        $this->assertFalse($r['valid']);
        $this->assertFalse($r['rejected']);
    }

    // ── stream-specific contracts ─────────────────────────────────────────────

    public function test_code_facts_stream_has_hourly_freshness(): void
    {
        $stream = $this->findStream('code_facts');
        $this->assertSame('hourly', $stream['freshness_expectation']);
    }

    public function test_queue_health_stream_has_real_time_freshness(): void
    {
        $stream = $this->findStream('queue_health');
        $this->assertSame('real_time', $stream['freshness_expectation']);
    }

    public function test_docs_drift_stream_influences_thesis_generation(): void
    {
        $stream = $this->findStream('docs_drift');
        $this->assertContains('thesis_generation', $stream['influences']);
    }

    public function test_give_back_reasons_influences_poison_packet_detection(): void
    {
        $stream = $this->findStream('give_back_reasons');
        $this->assertContains('poison_packet_detection', $stream['influences']);
    }

    // ── freshness_status + trust_tier ────────────────────────────────────────

    public function test_validate_returns_freshness_status_and_trust_tier_keys(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => 1751290000,
        ]);

        $this->assertArrayHasKey('freshness_status', $r);
        $this->assertArrayHasKey('trust_tier', $r);
        $this->assertArrayHasKey('usable_for_origination', $r);
        $this->assertArrayHasKey('staleness_reason', $r);
    }

    public function test_validate_fresh_record_has_fresh_status(): void
    {
        $now = 1751290000;
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => $now - 60,
        ], $now);

        $this->assertSame('fresh', $r['freshness_status']);
        $this->assertNull($r['staleness_reason']);
        $this->assertTrue($r['usable_for_origination']);
    }

    public function test_validate_freshness_unknown_when_no_now_provided(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => 1751290000,
        ]); // nowUnix defaults to 0

        $this->assertSame('unknown', $r['freshness_status']);
    }

    public function test_validate_freshness_unknown_when_timestamp_field_absent(): void
    {
        $now = 1751290000;
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0,
            // oldest_queued_at_unix intentionally omitted
        ], $now);

        $this->assertSame('unknown', $r['freshness_status']);
    }

    public function test_validate_trust_tier_verified_for_task_serving_disk_streams(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => 1751290000,
        ]);

        $this->assertSame('verified', $r['trust_tier']);
    }

    public function test_validate_trust_tier_audited_for_evidence_ledger_stream(): void
    {
        $r = $this->map->validate('runtime_receipts', [
            'event_type' => 'cert_passed', 'subject_id' => 't1',
            'recorded_at_unix' => 1751290000, 'payload_hash' => 'abc',
        ]);

        $this->assertSame('audited', $r['trust_tier']);
    }

    public function test_validate_trust_tier_derived_for_code_intelligence_stream(): void
    {
        $r = $this->map->validate('code_facts', [
            'workspace' => 'atlas-server', 'symbol_count' => 100,
            'orphan_count' => 5, 'indexed_at_unix' => 1751290000,
        ]);

        $this->assertSame('derived', $r['trust_tier']);
    }

    // ── AC2: stale + origination-sensitive = not usable ──────────────────────

    public function test_stale_queue_health_is_not_usable_for_origination(): void
    {
        $now = 1751290000;
        // oldest_queued_at_unix is 1000 seconds ago — exceeds real_time TTL of 300s
        $r = $this->map->validate('queue_health', [
            'depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => $now - 1000,
        ], $now);

        $this->assertSame('stale', $r['freshness_status']);
        $this->assertFalse($r['usable_for_origination']);
        $this->assertNotNull($r['staleness_reason'], 'staleness reason must be visible');
        $this->assertStringContainsString('1000s', $r['staleness_reason']);
    }

    public function test_stale_code_facts_is_not_usable_for_origination(): void
    {
        $now = 1751290000;
        // indexed 7200 seconds ago — exceeds hourly TTL of 3600s
        $r = $this->map->validate('code_facts', [
            'workspace' => 'atlas-server', 'symbol_count' => 100,
            'orphan_count' => 5, 'indexed_at_unix' => $now - 7200,
        ], $now);

        $this->assertSame('stale', $r['freshness_status']);
        $this->assertFalse($r['usable_for_origination']);
        $this->assertNotNull($r['staleness_reason']);
    }

    public function test_stale_muscle_outcomes_is_not_usable_for_origination(): void
    {
        $now = 1751290000;
        // reported 600 seconds ago — exceeds real_time TTL of 300s
        $r = $this->map->validate('muscle_outcomes', [
            'task_packet_id' => 't1', 'outcome' => 'success',
            'worker_id' => 'w1', 'reported_at_unix' => $now - 600,
        ], $now);

        $this->assertSame('stale', $r['freshness_status']);
        $this->assertFalse($r['usable_for_origination']);
    }

    public function test_stale_docs_drift_is_still_usable_for_origination(): void
    {
        // docs_drift is NOT in ORIGINATION_SENSITIVE_STREAMS — stale is still usable
        $now = 1751290000;
        $r = $this->map->validate('docs_drift', [
            'doc_path' => 'docs/foo.md', 'last_modified_unix' => $now - 172800, // 2 days
            'drift_score' => 0.3,
        ], $now);

        $this->assertSame('stale', $r['freshness_status']);
        $this->assertTrue($r['usable_for_origination'], 'docs_drift stale is still usable');
        $this->assertNotNull($r['staleness_reason'], 'staleness reason must still be visible');
    }

    // ── decision_influences ────────────────────────────────────────────────────

    public function test_validate_returns_decision_influences_for_valid_stream(): void
    {
        $r = $this->map->validate('queue_health', [
            'depth' => 12, 'stall_count' => 2, 'oldest_queued_at_unix' => 1751290000,
        ]);

        $this->assertArrayHasKey('decision_influences', $r);
        $this->assertContains('origination_rate', $r['decision_influences']);
    }

    public function test_validate_returns_empty_decision_influences_for_rejected_stream(): void
    {
        $r = $this->map->validate('chat_memory', ['anything' => 'goes']);

        $this->assertSame([], $r['decision_influences']);
    }

    public function test_chat_memory_cannot_be_promoted_by_adding_extra_fields(): void
    {
        $r = $this->map->validate('chat_memory', [
            'depth' => 12, 'stall_count' => 2, 'oldest_queued_at_unix' => 1751290000,
            'task_packet_id' => 'tp-1', 'outcome' => 'success', 'worker_id' => 'w1', 'reported_at_unix' => 1751290000,
        ]);

        $this->assertFalse($r['valid']);
        $this->assertTrue($r['rejected']);
        $this->assertFalse($r['usable_for_origination']);
    }

    public function test_raw_provider_prompt_cannot_be_promoted_by_adding_extra_fields(): void
    {
        $r = $this->map->validate('raw_provider_prompt', [
            'depth' => 12, 'stall_count' => 2, 'oldest_queued_at_unix' => 1751290000,
        ]);

        $this->assertFalse($r['valid']);
        $this->assertTrue($r['rejected']);
        $this->assertFalse($r['usable_for_origination']);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function findStream(string $id): array
    {
        foreach ($this->map->describe()['streams'] as $s) {
            if ($s['stream_id'] === $id) {
                return $s;
            }
        }
        $this->fail("Stream '{$id}' not found");
    }
}
