<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Leasing;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeaseRegistryShaper;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive pure registry-shaping concern extracted from
 * AgentControlPlaneClaimLeaseRepository into AgentControlPlaneLeaseRegistryShaper.
 *
 * Two methods migrated verbatim:
 *  - registryEntryFromLease: project a full lease into a compact 9-key registry index entry.
 *  - compactRegistry: capacity-bound the registry to MAX_REGISTRY_ENTRIES, sorting active
 *    leases first then by `expires_at_unix` DESC; stamp a compaction receipt on truncation.
 *
 * Pure / stateless / zero Laravel surface (CarbonImmutable provides the compaction timestamp).
 */
final class AgentControlPlaneLeaseRegistryShaperTest extends TestCase
{
    private AgentControlPlaneLeaseRegistryShaper $shaper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shaper = new AgentControlPlaneLeaseRegistryShaper;
    }

    public function test_constants_match_the_god_class_contract(): void
    {
        // The byte-identical contract: shaper constants must equal the original repo's values,
        // so any code reading these (e.g. via the queue / orchestrator) stays stable.
        $this->assertSame(1_000, AgentControlPlaneLeaseRegistryShaper::MAX_REGISTRY_ENTRIES);
        $this->assertSame('active', AgentControlPlaneLeaseRegistryShaper::LEASE_STATUS_ACTIVE);
    }

    // --- registryEntryFromLease -------------------------------------------

    public function test_registry_entry_projects_all_keys(): void
    {
        $lease = [
            'lease_id' => 'L1',
            'task_packet_id' => 'TP1',
            'agent_id' => 'A1',
            'lease_status' => 'active',
            'acquired_at' => '2026-06-26T10:00:00+00:00',
            'expires_at' => '2026-06-26T11:00:00+00:00',
            'expires_at_unix' => 1719495600,
            'write_set' => ['app/Foo.php'],
            'read_set' => ['tests/FooTest.php'],
        ];

        $entry = $this->shaper->registryEntryFromLease($lease);

        $this->assertSame('L1', $entry['lease_id']);
        $this->assertSame('TP1', $entry['task_packet_id']);
        $this->assertSame('A1', $entry['agent_id']);
        $this->assertSame('active', $entry['lease_status']);
        $this->assertSame(1719495600, $entry['expires_at_unix']);
        $this->assertSame(['app/Foo.php'], $entry['write_set']);
        $this->assertSame(['tests/FooTest.php'], $entry['read_set']);
        $this->assertStringStartsWith('sha256:', $entry['write_set_hash']);
        $this->assertStringStartsWith('sha256:', $entry['read_set_hash']);
    }

    public function test_registry_entry_defaults_missing_fields(): void
    {
        $entry = $this->shaper->registryEntryFromLease([]);

        $this->assertSame('', $entry['lease_id']);
        $this->assertSame('', $entry['task_packet_id']);
        $this->assertSame('', $entry['agent_id']);
        $this->assertSame('', $entry['lease_status']);
        $this->assertSame('', $entry['acquired_at']);
        $this->assertSame('', $entry['expires_at']);
        $this->assertSame(0, $entry['expires_at_unix']);
        $this->assertSame([], $entry['write_set']);
        $this->assertSame([], $entry['read_set']);
        $this->assertStringStartsWith('sha256:', $entry['write_set_hash']);
        $this->assertStringStartsWith('sha256:', $entry['read_set_hash']);
    }

    public function test_write_set_normalized_dedup_sorted_filter_empty(): void
    {
        $entry = $this->shaper->registryEntryFromLease([
            'write_set' => ['app/B.php', '', 'app/A.php', 'app/B.php'],
        ]);
        $this->assertSame(['app/A.php', 'app/B.php'], $entry['write_set']);
    }

    public function test_read_set_normalized_dedup_sorted_filter_empty(): void
    {
        $entry = $this->shaper->registryEntryFromLease([
            'read_set' => ['z.php', '', 'a.php', 'z.php'],
        ]);
        $this->assertSame(['a.php', 'z.php'], $entry['read_set']);
    }

    public function test_write_set_hash_is_deterministic(): void
    {
        $e1 = $this->shaper->registryEntryFromLease(['write_set' => ['app/Foo.php']]);
        $e2 = $this->shaper->registryEntryFromLease(['write_set' => ['app/Foo.php']]);
        $this->assertSame($e1['write_set_hash'], $e2['write_set_hash']);
    }

    public function test_write_set_hash_differs_for_different_paths(): void
    {
        $e1 = $this->shaper->registryEntryFromLease(['write_set' => ['app/Foo.php']]);
        $e2 = $this->shaper->registryEntryFromLease(['write_set' => ['app/Bar.php']]);
        $this->assertNotSame($e1['write_set_hash'], $e2['write_set_hash']);
    }

    public function test_compact_registry_tie_breaks_deterministically_by_lease_id(): void
    {
        // Two entries with the same status and expires_at_unix — order must be by lease_id asc.
        $entries = [
            ['lease_id' => 'L-Z', 'task_packet_id' => '', 'lease_status' => 'released', 'expires_at_unix' => 100],
            ['lease_id' => 'L-A', 'task_packet_id' => '', 'lease_status' => 'released', 'expires_at_unix' => 100],
        ];
        // Pad to exceed MAX to trigger sort.
        for ($i = 0; $i < 1_000; $i++) {
            $entries[] = ['lease_id' => "pad-$i", 'task_packet_id' => '', 'lease_status' => 'released', 'expires_at_unix' => -$i];
        }

        $out = $this->shaper->compactRegistry(['entries' => $entries]);

        // L-A < L-Z lexicographically.
        $this->assertSame('L-A', $out['entries'][0]['lease_id']);
        $this->assertSame('L-Z', $out['entries'][1]['lease_id']);
    }

    public function test_registry_entry_casts_non_string_fields_via_string_conversion(): void
    {
        // expires_at_unix is the only field with a numeric cast — others are string casts.
        $entry = $this->shaper->registryEntryFromLease([
            'lease_id' => 42,           // cast to '42'
            'expires_at_unix' => '1719', // cast to 1719 (int)
        ]);

        $this->assertSame('42', $entry['lease_id']);
        $this->assertSame(1719, $entry['expires_at_unix']);
    }

    // --- compactRegistry --------------------------------------------------

    public function test_compact_registry_passes_through_when_under_cap(): void
    {
        $registry = ['entries' => [
            ['lease_id' => 'L1', 'lease_status' => 'active', 'expires_at_unix' => 100],
            ['lease_id' => 'L2', 'lease_status' => 'released', 'expires_at_unix' => 50],
        ]];

        $out = $this->shaper->compactRegistry($registry);

        // No compaction receipt stamped.
        $this->assertArrayNotHasKey('compacted', $out);
        $this->assertArrayNotHasKey('compacted_at', $out);
        // Entries reindexed and preserved verbatim.
        $this->assertCount(2, $out['entries']);
        $this->assertSame('L1', $out['entries'][0]['lease_id']);
        $this->assertSame('L2', $out['entries'][1]['lease_id']);
    }

    public function test_compact_registry_truncates_to_max_when_exceeded(): void
    {
        $entries = [];
        // 1 active + 1_000 non-active => 1_001 entries, cap is 1_000.
        $entries[] = ['lease_id' => 'L-active', 'lease_status' => 'active', 'expires_at_unix' => 999];
        for ($i = 0; $i < 1_000; $i++) {
            $entries[] = ['lease_id' => "L-released-$i", 'lease_status' => 'released', 'expires_at_unix' => $i];
        }

        $out = $this->shaper->compactRegistry(['entries' => $entries]);

        $this->assertCount(1_000, $out['entries']);
        // The active lease must be first (kept-active-first policy).
        $this->assertSame('L-active', $out['entries'][0]['lease_id']);
    }

    public function test_compact_registry_stamps_compaction_receipt_when_truncated(): void
    {
        $entries = [];
        for ($i = 0; $i < 1_001; $i++) {
            $entries[] = ['lease_id' => "L-$i", 'lease_status' => 'released', 'expires_at_unix' => $i];
        }

        $out = $this->shaper->compactRegistry(['entries' => $entries]);

        $this->assertTrue($out['compacted']);
        $this->assertSame(1_001, $out['compacted_from_entry_count']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $out['compacted_at']);
        $this->assertSame(
            'keep_active_leases_first_then_recent_index_entries_without_deleting_lease_files',
            $out['compaction_policy'],
        );
    }

    public function test_compact_registry_sorts_active_first_then_by_expires_at_unix_desc(): void
    {
        // compactRegistry only sorts when entries > MAX_REGISTRY_ENTRIES — drive that branch by
        // padding to cap+1 so the usort runs and produces the active-first / expires_at_unix-desc
        // ordering the god-class promises.
        $entries = [
            ['lease_id' => 'L-released-recent', 'lease_status' => 'released', 'expires_at_unix' => 500],
            ['lease_id' => 'L-active-old', 'lease_status' => 'active', 'expires_at_unix' => 100],
            ['lease_id' => 'L-active-new', 'lease_status' => 'active', 'expires_at_unix' => 300],
            ['lease_id' => 'L-released-old', 'lease_status' => 'released', 'expires_at_unix' => 50],
        ];
        // Pad with a unique non-array filter target so the count exceeds MAX. We can't add raw
        // non-array entries (they get filtered) — use inert entries that won't affect the
        // sorted-head assertion.
        for ($i = 0; $i < 1_000; $i++) {
            $entries[] = ['lease_id' => "pad-$i", 'lease_status' => 'released', 'expires_at_unix' => -$i];
        }

        $out = $this->shaper->compactRegistry(['entries' => $entries]);

        // Active leases first; within active, expires_at_unix DESC; then released, expires_at_unix DESC.
        $this->assertSame('L-active-new', $out['entries'][0]['lease_id']);
        $this->assertSame('L-active-old', $out['entries'][1]['lease_id']);
        $this->assertSame('L-released-recent', $out['entries'][2]['lease_id']);
        $this->assertSame('L-released-old', $out['entries'][3]['lease_id']);
    }

    public function test_compact_registry_filters_non_array_entries(): void
    {
        $registry = [
            'entries' => [
                ['lease_id' => 'L1'],
                'not-an-array',
                null,
                ['lease_id' => 'L2'],
            ],
        ];

        $out = $this->shaper->compactRegistry($registry);

        $this->assertCount(2, $out['entries']);
        $this->assertSame('L1', $out['entries'][0]['lease_id']);
        $this->assertSame('L2', $out['entries'][1]['lease_id']);
    }

    public function test_compact_registry_handles_missing_entries_key(): void
    {
        $out = $this->shaper->compactRegistry([]);

        $this->assertSame([], $out['entries']);
        $this->assertArrayNotHasKey('compacted', $out);
    }

    public function test_compact_registry_at_exactly_max_passes_through_without_receipt(): void
    {
        // Exactly MAX_REGISTRY_ENTRIES — no truncation, no receipt.
        $entries = [];
        for ($i = 0; $i < 1_000; $i++) {
            $entries[] = ['lease_id' => "L-$i", 'lease_status' => 'released', 'expires_at_unix' => $i];
        }

        $out = $this->shaper->compactRegistry(['entries' => $entries]);

        $this->assertCount(1_000, $out['entries']);
        $this->assertArrayNotHasKey('compacted', $out);
    }
}
