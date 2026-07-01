<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseClaimParityInspector;
use Tests\TestCase;

final class AtlasTaskServingLeaseClaimParityInspectorTest extends TestCase
{
    private function svc(): AtlasTaskServingLeaseClaimParityInspector
    {
        return new AtlasTaskServingLeaseClaimParityInspector;
    }

    private function lease(string $leaseId, string $taskId): array
    {
        return ['lease_id' => $leaseId, 'task_packet_id' => $taskId];
    }

    private function record(string $taskId, string $status): array
    {
        return ['task_packet_id' => $taskId, 'status' => $status];
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->inspect([], []);

        foreach (['active_leases', 'claimed_records', 'matched_pairs', 'lease_without_claim', 'claim_without_lease', 'terminal_with_active_lease', 'recoverable_candidates', 'severity'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::SCHEMA, $r['schema']);
    }

    public function test_recoverable_candidates_has_total_and_items(): void
    {
        $r = $this->svc()->inspect([], []);

        $this->assertArrayHasKey('total', $r['recoverable_candidates']);
        $this->assertArrayHasKey('items', $r['recoverable_candidates']);
    }

    // ── clean parity ─────────────────────────────────────────────────────────

    public function test_clean_parity_when_every_lease_has_a_matching_claim(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1')],
            [$this->record('t1', 'claimed')],
        );

        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY, $r['classification']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::SEVERITY_NONE, $r['severity']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::ACTION_OBSERVE, $r['recommended_next_action']);
        $this->assertContains('t1', $r['matched_pairs']);
        $this->assertSame(0, $r['recoverable_candidates']['total']);
    }

    public function test_empty_inputs_are_clean_parity(): void
    {
        $r = $this->svc()->inspect([], []);

        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY, $r['classification']);
    }

    // ── missing claim (lease_without_claim) ─────────────────────────────────────

    public function test_lease_without_any_record_is_lease_without_claim(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1'), $this->lease('L2', 't2')],
            [$this->record('t1', 'claimed')],
        );

        $this->assertContains('t2', $r['lease_without_claim']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_WITHOUT_CLAIM, $r['classification']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES, $r['recommended_next_action']);
        $this->assertContains('t2', $r['recoverable_candidates']['items']);
    }

    // ── missing lease (claim_without_lease) ─────────────────────────────────────

    public function test_claimed_record_without_active_lease_is_claim_without_lease(): void
    {
        $r = $this->svc()->inspect(
            [],
            [$this->record('t1', 'claimed')],
        );

        $this->assertContains('t1', $r['claim_without_lease']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLAIM_WITHOUT_LEASE, $r['classification']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::ACTION_INVESTIGATE_WRITER, $r['recommended_next_action']);
    }

    // ── terminal-with-active-lease ───────────────────────────────────────────────

    public function test_terminal_record_with_still_active_lease_is_flagged(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1')],
            [$this->record('t1', 'completed')],
        );

        $this->assertContains('t1', $r['terminal_with_active_lease']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE, $r['classification']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::SEVERITY_HIGH, $r['severity']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES, $r['recommended_next_action']);
        $this->assertContains('t1', $r['recoverable_candidates']['items']);
    }

    public function test_terminal_with_active_lease_takes_priority_over_other_signals(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1'), $this->lease('L2', 't2')],
            [$this->record('t1', 'completed'), $this->record('t3', 'claimed')],
        );

        // t1=terminal-with-lease, t2=lease-without-claim, t3=claim-without-lease — terminal wins.
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE, $r['classification']);
    }

    // ── AC2: duplicate active lease rows for the SAME task → lease_registry_duplicate_drift ──

    public function test_more_lease_rows_than_record_rows_with_zero_recoverable_is_lease_registry_drift(): void
    {
        // Two lease envelope rows for the SAME task (registry drift) but the task itself is cleanly
        // matched to its one claim record — recoverable=0, yet active_leases(2) > claimed_records(1).
        // This is the MORE SPECIFIC duplicate-active-lease-envelope case: named explicitly as
        // lease_registry_duplicate_drift (not the generic count-mismatch lease_registry_drift)
        // so a repeated lease leak incident is diagnosable by task id without guessing.
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1'), $this->lease('L1-renewed', 't1')],
            [$this->record('t1', 'claimed')],
        );

        $this->assertSame(2, $r['active_leases']);
        $this->assertSame(1, $r['claimed_records']);
        $this->assertSame(0, $r['recoverable_candidates']['total']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_REGISTRY_DUPLICATE_DRIFT, $r['classification']);
        $this->assertNotSame(AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY, $r['classification']);
        $this->assertNotSame('dry_queue', $r['classification']);
        $this->assertNotSame('worker_failure', $r['classification']);
        $this->assertSame(AtlasTaskServingLeaseClaimParityInspector::ACTION_REPAIR_REGISTRY, $r['recommended_next_action']);
        $this->assertContains('t1', $r['duplicate_task_packet_ids']);
        $this->assertSame(2, $r['duplicate_lease_counts']['t1']);
    }

    // ── recommended_next_action is limited to the 4 allowed values ──────────────

    public function test_recommended_next_action_is_always_one_of_four_values(): void
    {
        $valid = [
            AtlasTaskServingLeaseClaimParityInspector::ACTION_OBSERVE,
            AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES,
            AtlasTaskServingLeaseClaimParityInspector::ACTION_REPAIR_REGISTRY,
            AtlasTaskServingLeaseClaimParityInspector::ACTION_INVESTIGATE_WRITER,
        ];

        $scenarios = [
            $this->svc()->inspect([], []),
            $this->svc()->inspect([$this->lease('L1', 't1')], [$this->record('t1', 'claimed')]),
            $this->svc()->inspect([$this->lease('L1', 't1')], []),
            $this->svc()->inspect([], [$this->record('t1', 'claimed')]),
            $this->svc()->inspect([$this->lease('L1', 't1')], [$this->record('t1', 'completed')]),
        ];

        foreach ($scenarios as $r) {
            $this->assertContains($r['recommended_next_action'], $valid);
        }
    }

    // ── purity ────────────────────────────────────────────────────────────────

    public function test_inspect_is_deterministic(): void
    {
        $leases = [$this->lease('L1', 't1')];
        $records = [$this->record('t1', 'claimed')];

        $a = $this->svc()->inspect($leases, $records);
        $b = $this->svc()->inspect($leases, $records);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    // ── AC: reports recoverable_leaks, ghost_active_leases, claimed_without_lease, clean parity separately ──

    public function test_clean_state_reports_clean_parity_true_and_empty_leak_ghost_buckets(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1')],
            [$this->record('t1', 'claimed')],
        );

        $this->assertTrue($r['clean_parity']);
        $this->assertSame(0, $r['recoverable_leaks']['total']);
        $this->assertSame(0, $r['ghost_active_leases']['total']);
        $this->assertSame([], $r['claimed_without_lease']);
    }

    public function test_real_recoverable_leak_reports_terminal_lease_in_recoverable_leaks_not_ghost(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1')],
            [$this->record('t1', 'completed')],
        );

        $this->assertFalse($r['clean_parity']);
        $this->assertSame(1, $r['recoverable_leaks']['total']);
        $this->assertContains('t1', $r['recoverable_leaks']['items']);
        $this->assertSame(0, $r['ghost_active_leases']['total']);
    }

    public function test_ghost_active_lease_with_no_record_reports_in_ghost_bucket_not_recoverable_leaks(): void
    {
        $r = $this->svc()->inspect(
            [$this->lease('L1', 't1')],
            [],
        );

        $this->assertFalse($r['clean_parity']);
        $this->assertSame(1, $r['ghost_active_leases']['total']);
        $this->assertContains('t1', $r['ghost_active_leases']['items']);
        $this->assertSame(0, $r['recoverable_leaks']['total']);
    }

    public function test_claimed_without_lease_mismatch_is_reported_separately(): void
    {
        $r = $this->svc()->inspect(
            [],
            [$this->record('t1', 'claimed')],
        );

        $this->assertFalse($r['clean_parity']);
        $this->assertContains('t1', $r['claimed_without_lease']);
        $this->assertSame(0, $r['recoverable_leaks']['total']);
        $this->assertSame(0, $r['ghost_active_leases']['total']);
    }
}
