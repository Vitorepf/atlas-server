<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReceiptPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasProjectLaneReceiptPolicy: each of the 4 allowlisted event types yields a canonical envelope
 * with envelope_hash; unknown event_type and empty evidence_hash throw without mutating storage; identical
 * facts ⇒ byte-identical envelope_hash (deterministic).
 */
final class AtlasProjectLaneReceiptPolicyTest extends TestCase
{
    private function facts(string $event, string $evidenceHash = 'evid-hash-1'): array
    {
        return [
            'project_id' => 'demo-lane',
            'lane_namespace' => 'lane.demo-lane.deadbeef.main',
            'event_type' => $event,
            'evidence_hash' => $evidenceHash,
            'provider_safe_source_refs' => ['s-1', 's-2'],
            'previous_envelope_hash' => 'abcd1234',
            'lane_epoch' => 1,
            'event_sequence' => 42,
            'created_at' => '2026-06-25T00:00:00Z',
        ];
    }

    public function test_all_four_allowed_events_build_canonical_envelope(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        foreach (AtlasProjectLaneReceiptPolicy::ALLOWED_EVENTS as $event) {
            $env = $p->build($this->facts($event));
            $this->assertSame($event, $env['event_type']);
            $this->assertSame(AtlasProjectLaneReceiptPolicy::SCHEMA, $env['schema']);
            $this->assertSame(64, strlen($env['envelope_hash']));
        }
    }

    public function test_envelope_carries_all_required_fields(): void
    {
        $env = (new AtlasProjectLaneReceiptPolicy)->build($this->facts(AtlasProjectLaneReceiptPolicy::EVENT_LANE_ADMITTED));
        foreach (['project_id', 'lane_namespace', 'event_type', 'evidence_hash', 'provider_safe_source_refs', 'previous_envelope_hash', 'lane_epoch', 'event_sequence', 'created_at', 'envelope_hash'] as $key) {
            $this->assertArrayHasKey($key, $env, "envelope must include {$key}");
        }
        $this->assertIsInt($env['lane_epoch']);
        $this->assertIsInt($env['event_sequence']);
    }

    public function test_unknown_event_type_throws_without_mutating_storage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown event_type/');
        (new AtlasProjectLaneReceiptPolicy)->build($this->facts('release_yolo_now'));
    }

    public function test_empty_evidence_hash_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty evidence_hash/');
        (new AtlasProjectLaneReceiptPolicy)->build($this->facts(AtlasProjectLaneReceiptPolicy::EVENT_TASK_VERIFIED, ''));
    }

    public function test_identical_facts_produce_byte_identical_envelope_hash(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        $a = $p->build($this->facts(AtlasProjectLaneReceiptPolicy::EVENT_LEARNING_RECORDED));
        $b = $p->build($this->facts(AtlasProjectLaneReceiptPolicy::EVENT_LEARNING_RECORDED));
        $this->assertSame($a['envelope_hash'], $b['envelope_hash']);
    }

    public function test_source_hashes_are_sorted_for_deterministic_envelope_hash(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        $f1 = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_TASK_VERIFIED);
        $f1['provider_safe_source_refs'] = ['z-3', 'a-1', 'm-2'];
        $f2 = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_TASK_VERIFIED);
        $f2['provider_safe_source_refs'] = ['m-2', 'a-1', 'z-3'];
        $this->assertSame($p->build($f1)['envelope_hash'], $p->build($f2)['envelope_hash']);
    }

    public function test_raw_prompt_provider_and_private_trace_refs_are_rejected(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        foreach (['prompt:sys-prompt', 'provider:anthropic', 'private:key-123', 'trace:span-456'] as $unsafe) {
            $f = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_TASK_VERIFIED);
            $f['provider_safe_source_refs'] = [$unsafe];
            try {
                $p->build($f);
                $this->fail("Expected rejection for unsafe ref: {$unsafe}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('provider-unsafe source_ref rejected', $e->getMessage());
            }
        }
        // Safe ref passes
        $f = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_TASK_VERIFIED);
        $f['provider_safe_source_refs'] = ['receipt:abc123', 'evidence:xyz'];
        $env = $p->build($f);
        $this->assertSame(['evidence:xyz', 'receipt:abc123'], $env['provider_safe_source_refs']); // sorted
    }

    public function test_envelope_hash_changes_when_chain_fields_change(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        $base = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_RELEASE_DECIDED);

        $different_prev = array_merge($base, ['previous_envelope_hash' => 'different-prev-hash']);
        $different_seq = array_merge($base, ['event_sequence' => 99]);

        $hashBase = $p->build($base)['envelope_hash'];
        $this->assertNotSame($hashBase, $p->build($different_prev)['envelope_hash'], 'previous_envelope_hash must affect envelope_hash');
        $this->assertNotSame($hashBase, $p->build($different_seq)['envelope_hash'], 'event_sequence must affect envelope_hash');
    }

    public function test_empty_project_id_or_namespace_throws(): void
    {
        $p = new AtlasProjectLaneReceiptPolicy;
        $bad = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_LANE_ADMITTED);
        $bad['project_id'] = '';
        try {
            $p->build($bad);
            $this->fail('expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('project_id', $e->getMessage());
        }
        $bad2 = $this->facts(AtlasProjectLaneReceiptPolicy::EVENT_LANE_ADMITTED);
        $bad2['lane_namespace'] = '';
        try {
            $p->build($bad2);
            $this->fail('expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lane_namespace', $e->getMessage());
        }
    }
}
