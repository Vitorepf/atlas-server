<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationStorageSchemaService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation STORAGE SCHEMA: the two tables and
 * their authoritative columns, the current file-backed runtime (events.jsonl /
 * projection.json / ledger.lock), the seven storage invariants checked in order,
 * the seven required tests, the migration field-family gate, and the read-only
 * Non Goal guarantee (no migration created, no storage written, no authority
 * granted).
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
 */
class AtlasDurableReservationStorageSchemaTest extends TestCase
{
    private function service(): AtlasDurableReservationStorageSchemaService
    {
        return new AtlasDurableReservationStorageSchemaService();
    }

    /** Doc "Tables": exactly two tables with their documented closed column sets. */
    public function test_tables_and_columns_are_the_documented_closed_sets(): void
    {
        $svc = $this->service();

        $this->assertSame([
            'atlas_self_construction_reservation_events',
            'atlas_self_construction_reservations',
        ], $svc->tables());

        // Event source columns include the audit-chain fields the doc lists.
        $this->assertSame([
            'reservation_id', 'packet_id', 'event_type', 'actor', 'session',
            'packet_hash', 'allowed_files_hash', 'previous_event_hash', 'event_hash', 'payload',
        ], $svc->eventColumns());

        // Projection columns include lease expiry, release/completion stamps and blocker.
        $this->assertSame([
            'packet_id', 'owner_session', 'state', 'packet_hash', 'allowed_files_hash',
            'lease_expires_at', 'released_at', 'completed_at', 'blocker_reason',
        ], $svc->reservationColumns());
    }

    /**
     * Doc: "The current runtime uses events.jsonl / projection.json /
     * ledger.lock" and Postgres "remains the promotion target". The live backend
     * is file-backed, not Postgres.
     */
    public function test_current_runtime_is_file_backed_with_postgres_as_promotion_target(): void
    {
        $runtime = $this->service()->currentRuntime();

        $this->assertSame('local_file', $runtime['backend']);
        $this->assertSame('postgres', $runtime['promotion_target']);
        $this->assertSame([
            'events' => 'events.jsonl',
            'projection' => 'projection.json',
            'lock' => 'ledger.lock',
        ], $runtime['artifacts']);
    }

    /**
     * Doc "Required Tests": "migration contains hash, actor, state, lease and
     * payload fields". The gate fails CLOSED when a whole family is absent.
     */
    public function test_migration_field_gate_fails_closed_when_a_family_is_missing(): void
    {
        $svc = $this->service();

        // A migration carrying one column from every family => present.
        $complete = $svc->migrationFieldsPresent([
            'event_hash',          // hash family
            'actor',               // actor family
            'event_type',          // state family
            'lease_expires_at',    // lease family
            'payload',             // payload family
        ]);
        $this->assertTrue($complete['present']);
        $this->assertSame([], $complete['missing_families']);

        // Drop every payload column => the payload family is unsatisfied.
        $missingPayload = $svc->migrationFieldsPresent([
            'event_hash', 'actor', 'event_type', 'lease_expires_at',
        ]);
        $this->assertFalse($missingPayload['present']);
        $this->assertSame(['payload'], $missingPayload['missing_families']);
    }

    /**
     * Doc invariant "Exactly one active claimed reservation may exist per packet":
     * a second claim on a packet with an active claim is blocked at that invariant.
     */
    public function test_duplicate_active_packet_claim_is_blocked(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-dup',
            'allowed_files' => ['app/Services/Foo/A.php'],
            'projection' => [
                ['packet_id' => 'pkt-dup', 'state' => 'claimed', 'allowed_files' => ['app/Services/Foo/A.php']],
            ],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('exactly_one_active_claim_per_packet', $result['violated_invariant']);
        $this->assertSame('packet_already_claimed', $result['blocking_reason']);
    }

    /**
     * Doc invariant "Active reservations with overlapping allowed files block new
     * claims": a different packet that shares an allowed file with an active
     * reservation is blocked at the file-overlap invariant, naming the file.
     */
    public function test_overlapping_active_file_scope_is_blocked(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-new',
            'allowed_files' => ['app/Services/Shared/Conflict.php'],
            'projection' => [
                ['packet_id' => 'pkt-other', 'state' => 'claimed', 'allowed_files' => ['app/Services/Shared/Conflict.php']],
            ],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('overlapping_allowed_files_block_new_claims', $result['violated_invariant']);
        $this->assertSame(['app/Services/Shared/Conflict.php'], $result['overlapping_files']);
    }

    /**
     * Doc invariant "Hot Voice/Kernel scopes are never claimable": an allowed file
     * under a hot scope blocks the claim even with an empty projection.
     */
    public function test_hot_voice_kernel_scope_is_never_claimable(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-hot',
            'allowed_files' => ['app/Services/Ai/Voice/RealtimeSession.php'],
            'projection' => [],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('hot_voice_kernel_scopes_never_claimable', $result['violated_invariant']);
        $this->assertSame(['app/Services/Ai/Voice/RealtimeSession.php'], $result['hot_scope_matches']);
    }

    /** Doc invariant "Expired leases cannot mark completion": an expired lease is refused. */
    public function test_expired_lease_cannot_complete_but_active_can(): void
    {
        $svc = $this->service();

        $expired = $svc->completion([
            'state' => 'claimed',
            'lease_expires_at' => '2000-01-01T00:00:00+00:00',
        ]);
        $this->assertFalse($expired['accepted']);
        $this->assertSame('expired', $expired['from_state']);
        $this->assertSame('lease_expired', $expired['reason']);

        $active = $svc->completion([
            'state' => 'claimed',
            'lease_expires_at' => date('c', time() + 3600),
        ]);
        $this->assertTrue($active['accepted']);
        $this->assertSame('completed', $active['to_state']);
    }

    /**
     * Doc "Required Invariants" ORDER: checkInvariants stops at the EARLIEST
     * violated invariant. With BOTH a broken prior-hash chain (invariant 2) and a
     * duplicate active claim (invariant 3), the prior-hash chain wins.
     */
    public function test_invariant_check_stops_at_earliest_violation(): void
    {
        $result = $this->service()->checkInvariants([
            'events' => [
                ['previous_event_hash' => null],          // first event: ok
                ['previous_event_hash' => null],          // second event: MUST chain — violation
            ],
            'projection' => [
                // Also a duplicate active claim (later invariant) — must NOT be reported first.
                ['packet_id' => 'pkt', 'state' => 'claimed'],
                ['packet_id' => 'pkt', 'state' => 'claimed'],
            ],
        ]);

        $this->assertFalse($result['holds']);
        $this->assertSame('each_event_references_prior_hash', $result['violated_invariant']);
        $this->assertSame('missing_prior_event_hash', $result['reason']);
    }

    /** Doc invariant "Dispatch remains disabled by this schema": enabling dispatch is a violation. */
    public function test_invariant_check_rejects_enabled_dispatch_and_passes_clean_snapshot(): void
    {
        $svc = $this->service();

        $clean = $svc->checkInvariants([
            'events' => [
                ['previous_event_hash' => null],
                ['previous_event_hash' => 'abc'],
            ],
            'projection' => [
                ['packet_id' => 'pkt-a', 'state' => 'claimed', 'allowed_files' => ['app/A.php']],
                ['packet_id' => 'pkt-b', 'state' => 'completed', 'allowed_files' => ['app/B.php']],
            ],
            'dispatch_enabled' => false,
        ]);
        $this->assertTrue($clean['holds']);
        $this->assertNull($clean['violated_invariant']);

        $dispatch = $svc->checkInvariants(['dispatch_enabled' => true]);
        $this->assertFalse($dispatch['holds']);
        $this->assertSame('dispatch_remains_disabled', $dispatch['violated_invariant']);
        $this->assertSame('dispatch_enabled_by_storage', $dispatch['reason']);
    }

    /**
     * Doc "Required Tests" + "Completion Criteria": the schema packet is fully
     * specified (ready) and enumerates the seven invariants and the seven tests.
     */
    public function test_schema_packet_is_ready_and_enumerates_seven_invariants_and_tests(): void
    {
        $svc = $this->service();
        $packet = $svc->schemaPacket();

        $this->assertTrue($packet['ready']);
        $this->assertCount(7, $packet['invariants']);
        $this->assertCount(7, $packet['required_tests']);

        // Required tests cover the documented duplicate, overlap, expiry, reclaim,
        // rebuild and read-only-command cases by name.
        $this->assertContains('duplicate_active_packet_claim_is_blocked', $svc->requiredTests());
        $this->assertContains('schema_command_does_not_create_migrations_or_write_storage', $svc->requiredTests());
        $this->assertContains('projection_can_be_rebuilt_from_events', $svc->requiredTests());

        // Both tables carry non-empty column sets and their documented roles.
        $this->assertSame('append_only_event_source', $packet['tables']['atlas_self_construction_reservation_events']['role']);
        $this->assertSame('rebuilt_projection', $packet['tables']['atlas_self_construction_reservations']['role']);
    }

    /**
     * Doc decision "Storage claims never grant dispatch, execution, auto-merge or
     * hot-scope authority" + the read-only schema-command rule: every surface
     * forces all six Non Goal flags false and the guarantee holds.
     */
    public function test_non_goal_guarantee_holds_across_decisions(): void
    {
        $svc = $this->service();
        $packet = $svc->schemaPacket();
        $claim = $svc->evaluateClaim([]);
        $invariants = $svc->checkInvariants([]);

        $this->assertSame([], $svc->assertGuaranteeHeld([$packet, $claim, $invariants]));

        // Every surface forces all six Non Goal flags false — including the
        // creates_migration and writes_storage read-only flags.
        $expected = array_fill_keys(AtlasDurableReservationStorageSchemaService::NON_GOAL_KEYS, false);
        foreach ([$packet, $claim, $invariants] as $r) {
            $this->assertSame($expected, $r['non_goals']);
        }
        $this->assertFalse($packet['non_goals']['creates_migration']);
        $this->assertFalse($packet['non_goals']['writes_storage']);
    }
}
