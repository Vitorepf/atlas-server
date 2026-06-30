<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceIntakeMap;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEvidenceIntakeMapTest extends TestCase
{
    private AtlasExternalBrainEvidenceIntakeMap $map;

    protected function setUp(): void
    {
        $this->map = new AtlasExternalBrainEvidenceIntakeMap;
    }

    // ── AC1: valid records pass when required fields + freshness satisfied ────

    public function test_valid_queue_health_record_passes(): void
    {
        $now    = 1_000_000;
        $result = $this->map->validate('queue_health', [
            'depth'                   => 12,
            'stall_count'             => 0,
            'oldest_queued_at_unix'   => $now - 60, // 60s old, real_time TTL=300
        ], $now);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['rejected']);
        $this->assertSame('fresh', $result['freshness_status']);
        $this->assertTrue($result['usable_for_origination']);
    }

    public function test_valid_muscle_outcomes_record_passes(): void
    {
        $now    = 2_000_000;
        $result = $this->map->validate('muscle_outcomes', [
            'task_packet_id' => 'task-1',
            'outcome'        => 'success',
            'worker_id'      => 'w-1',
            'reported_at_unix' => $now - 10,
        ], $now);

        $this->assertTrue($result['valid']);
        $this->assertSame('fresh', $result['freshness_status']);
        $this->assertTrue($result['usable_for_origination']);
    }

    public function test_valid_code_facts_with_hourly_ttl_passes(): void
    {
        $now    = 3_000_000;
        $result = $this->map->validate('code_facts', [
            'workspace'       => 'atlas-server',
            'symbol_count'    => 8700,
            'orphan_count'    => 135,
            'indexed_at_unix' => $now - 1800, // 30min, hourly TTL=3600
        ], $now);

        $this->assertTrue($result['valid']);
        $this->assertSame('fresh', $result['freshness_status']);
        $this->assertTrue($result['usable_for_origination']);
    }

    public function test_missing_required_fields_produces_invalid_with_list(): void
    {
        $result = $this->map->validate('queue_health', ['depth' => 5], 0);

        $this->assertFalse($result['valid']);
        $this->assertContains('stall_count',           $result['missing_fields']);
        $this->assertContains('oldest_queued_at_unix', $result['missing_fields']);
    }

    // ── AC2: stale / unsafe / unknown stream records are rejected ─────────────

    public function test_stale_queue_health_record_is_not_usable_for_origination(): void
    {
        $now    = 1_000_000;
        $result = $this->map->validate('queue_health', [
            'depth'                 => 5,
            'stall_count'           => 0,
            'oldest_queued_at_unix' => $now - 400, // 400s > 300s real_time TTL
        ], $now);

        $this->assertTrue($result['valid']);        // fields are all present
        $this->assertSame('stale', $result['freshness_status']);
        $this->assertNotNull($result['staleness_reason']);
        $this->assertFalse($result['usable_for_origination']); // origination-sensitive stream
    }

    public function test_stale_docs_drift_is_still_usable_because_not_origination_sensitive(): void
    {
        $now    = 5_000_000;
        $result = $this->map->validate('docs_drift', [
            'doc_path'          => 'docs/foo.md',
            'last_modified_unix' => $now - 100_000, // >> daily TTL=86400
            'drift_score'       => 0.5,
        ], $now);

        $this->assertSame('stale', $result['freshness_status']);
        // docs_drift is NOT in ORIGINATION_SENSITIVE_STREAMS
        $this->assertTrue($result['usable_for_origination']);
    }

    public function test_rejected_stream_chat_memory_is_rejected(): void
    {
        $result = $this->map->validate('chat_memory', ['anything' => true]);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['rejected']);
        $this->assertSame('rejected', $result['trust_tier']);
        $this->assertFalse($result['usable_for_origination']);
    }

    public function test_rejected_stream_raw_provider_prompt_is_rejected(): void
    {
        $result = $this->map->validate('raw_provider_prompt', []);

        $this->assertTrue($result['rejected']);
        $this->assertFalse($result['usable_for_origination']);
    }

    public function test_unknown_stream_id_is_invalid(): void
    {
        $result = $this->map->validate('invented_stream', []);

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['rejected']);
        $this->assertFalse($result['usable_for_origination']);
    }

    // ── AC3: describe exposes purpose, required_fields, freshness — no secrets ──

    public function test_describe_returns_schema_and_streams(): void
    {
        $d = $this->map->describe();

        $this->assertSame(AtlasExternalBrainEvidenceIntakeMap::SCHEMA, $d['schema']);
        $this->assertNotEmpty($d['streams']);
        $this->assertIsArray($d['rejected_sources']);
    }

    public function test_describe_streams_have_required_keys(): void
    {
        foreach ($this->map->describe()['streams'] as $s) {
            $this->assertArrayHasKey('stream_id',             $s);
            $this->assertArrayHasKey('source_type',           $s);
            $this->assertArrayHasKey('freshness_expectation', $s);
            $this->assertArrayHasKey('minimum_fields',        $s);
        }
    }

    public function test_describe_rejected_sources_contains_unsafe_streams(): void
    {
        $rejected = $this->map->describe()['rejected_sources'];

        $this->assertContains('chat_memory',         $rejected);
        $this->assertContains('raw_provider_prompt', $rejected);
    }

    // ── AC4: pure, deterministic, no I/O ─────────────────────────────────────

    public function test_validate_is_deterministic(): void
    {
        $now    = 9_000_000;
        $record = ['depth' => 5, 'stall_count' => 0, 'oldest_queued_at_unix' => $now - 10];

        $this->assertSame(
            json_encode($this->map->validate('queue_health', $record, $now)),
            json_encode($this->map->validate('queue_health', $record, $now))
        );
    }

    public function test_is_rejected_identifies_unsafe_streams(): void
    {
        $this->assertTrue($this->map->isRejected('chat_memory'));
        $this->assertFalse($this->map->isRejected('queue_health'));
    }
}
