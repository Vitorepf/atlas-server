<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSemanticDuplicateIndex;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricSemanticDuplicateIndexTest extends TestCase
{
    private AtlasTaskFabricSemanticDuplicateIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new AtlasTaskFabricSemanticDuplicateIndex();
    }

    // AC 2: different wording but same capability key + target family → semantic_duplicate
    public function test_different_wording_same_capability_key_family_is_duplicate(): void
    {
        $result = $this->index->check([
            'capability_key' => 'replenish_queue_buffer',
            'target_family' => 'QueueReplenisher',
            'acceptance_intent' => 'Ensure queue buffer stays above threshold',
        ], [
            [
                'task_packet_id' => 'existing-001',
                'capability_key' => 'Replenish Queue Buffer',
                'target_family' => 'QueueReplenisher',
                'acceptance_intent' => 'Keep the queue topped up',
            ],
        ]);

        $this->assertSame('semantic_duplicate', $result['status']);
    }

    // AC 3: same file but different capabilities → NOT duplicate
    public function test_same_file_different_capabilities_not_duplicate(): void
    {
        $result = $this->index->check([
            'capability_key' => 'add_rate_limit',
            'target_family' => 'Worker',
            'allowed_files' => ['app/Worker.php'],
            'acceptance_intent' => 'Add rate limiting',
        ], [
            [
                'task_packet_id' => 'existing-002',
                'capability_key' => 'add_circuit_breaker',
                'target_family' => 'Worker',
                'allowed_files' => ['app/Worker.php'],
                'acceptance_intent' => 'Add circuit breaker',
            ],
        ]);

        $this->assertSame('unique', $result['status']);
    }

    // AC 4: duplicate output includes matched packet id or target
    public function test_duplicate_includes_matched_packet_id(): void
    {
        $result = $this->index->check([
            'capability_key' => 'parse_metrics',
            'target_family' => 'MetricsParser',
            'acceptance_intent' => 'Parse metrics from file',
        ], [
            [
                'task_packet_id' => 'existing-003',
                'capability_key' => 'Parse Metrics',
                'target_family' => 'MetricsParser',
            ],
        ]);

        $this->assertSame('semantic_duplicate', $result['status']);
        $this->assertSame('existing-003', $result['matched_packet_id']);
        $this->assertSame('MetricsParser', $result['matched_target']);
    }

    public function test_empty_existing_list_is_unique(): void
    {
        $result = $this->index->check([
            'capability_key' => 'new_thing',
            'target_family' => 'New',
        ], []);

        $this->assertSame('unique', $result['status']);
    }

    public function test_same_capability_different_family_not_duplicate(): void
    {
        $result = $this->index->check([
            'capability_key' => 'emit_report',
            'target_family' => 'FamilyA',
        ], [
            [
                'task_packet_id' => 'existing-004',
                'capability_key' => 'emit_report',
                'target_family' => 'FamilyB',
            ],
        ]);

        $this->assertSame('unique', $result['status']);
    }

    public function test_acceptance_intent_match_same_family_is_duplicate(): void
    {
        $result = $this->index->check([
            'capability_key' => 'unique_key_a',
            'target_family' => 'AuditTrail',
            'acceptance_intent' => 'Record every mutation to ledger',
        ], [
            [
                'task_packet_id' => 'existing-005',
                'capability_key' => 'unique_key_b',
                'target_family' => 'AuditTrail',
                'acceptance_intent' => 'record-every-mutation-to-ledger',
            ],
        ]);

        $this->assertSame('semantic_duplicate', $result['status']);
    }

    // ── AC2: class-name variants in same family with overlapping files are duplicate ──

    public function test_different_class_same_capability_family_with_overlapping_files_is_duplicate(): void
    {
        $result = $this->index->check([
            'capability_key' => 'harden_gate',
            'target_family' => 'Gate',
            'allowed_files' => ['app/Gate/FooGate.php', 'app/Gate/BarGate.php'],
        ], [
            [
                'task_packet_id' => 'existing-gate',
                'capability_key' => 'Harden Gate',
                'target_family' => 'Gate',
                'allowed_files' => ['app/Gate/FooGate.php'],
            ],
        ]);

        $this->assertSame('semantic_duplicate', $result['status']);
        $this->assertSame('existing-gate', $result['matched_packet_id']);
    }

    public function test_same_capability_family_without_file_overlap_still_duplicate(): void
    {
        // Different allowed_files but same capability + family is still a rename variant.
        $result = $this->index->check([
            'capability_key' => 'harden_gate',
            'target_family' => 'Gate',
            'allowed_files' => ['app/Gate/BazGate.php'],
        ], [
            [
                'task_packet_id' => 'existing-gate',
                'capability_key' => 'Harden Gate',
                'target_family' => 'Gate',
                'allowed_files' => ['app/Gate/FooGate.php'],
            ],
        ]);

        $this->assertSame('semantic_duplicate', $result['status']);
    }

    // ── AC3: complementary unlock_chains or evidence_floor → NOT duplicate ───

    public function test_same_capability_family_but_distinct_unlock_chains_passes(): void
    {
        $result = $this->index->check([
            'capability_key' => 'parse_metrics',
            'target_family' => 'Metrics',
            'unlock_chains' => 'pipeline_a',
            'allowed_files' => ['app/Metrics/ParserA.php'],
        ], [
            [
                'task_packet_id' => 'existing-metrics',
                'capability_key' => 'parse_metrics',
                'target_family' => 'Metrics',
                'unlock_chains' => 'pipeline_b',
                'allowed_files' => ['app/Metrics/ParserB.php'],
            ],
        ]);

        $this->assertSame('unique', $result['status']);
    }

    public function test_same_capability_family_but_distinct_evidence_floor_passes(): void
    {
        $result = $this->index->check([
            'capability_key' => 'replicate_db',
            'target_family' => 'DbSync',
            'evidence_floor' => 'high',
            'allowed_files' => ['app/Db/SyncA.php'],
        ], [
            [
                'task_packet_id' => 'existing-db',
                'capability_key' => 'replicate_db',
                'target_family' => 'DbSync',
                'evidence_floor' => 'low',
                'allowed_files' => ['app/Db/SyncB.php'],
            ],
        ]);

        $this->assertSame('unique', $result['status']);
    }
}
