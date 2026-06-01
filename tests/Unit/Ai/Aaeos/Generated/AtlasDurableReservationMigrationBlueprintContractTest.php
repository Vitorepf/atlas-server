<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationMigrationBlueprintContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Migration Blueprint contract: the
 * ordered Future Migration Files, the two tables' required columns and indexes,
 * the uniqueness rules (unique event_hash; unique active packet claim guard),
 * the rollback rule "drop projection before events" and the read-only
 * non-execution guarantee.
 *
 * Pure in-memory contract logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md
 */
class AtlasDurableReservationMigrationBlueprintContractTest extends TestCase
{
    private function service(): AtlasDurableReservationMigrationBlueprintContractService
    {
        return new AtlasDurableReservationMigrationBlueprintContractService();
    }

    /**
     * Doc "Future Migration Files": events table created first, projection table
     * second, and the backfill/rebuild command is optional + gated on repository
     * and projection tests existing.
     */
    public function test_migration_files_order_events_then_projection_then_optional_backfill(): void
    {
        $files = $this->service()->migrationFiles();

        $this->assertCount(3, $files);

        $this->assertSame(1, $files[0]['order']);
        $this->assertSame('create_atlas_self_construction_reservation_events_table', $files[0]['name']);
        $this->assertSame('atlas_self_construction_reservation_events', $files[0]['creates']);
        $this->assertFalse($files[0]['optional']);

        $this->assertSame('create_atlas_self_construction_reservations_table', $files[1]['name']);
        $this->assertSame('atlas_self_construction_reservations', $files[1]['creates']);
        $this->assertFalse($files[1]['optional']);

        // The backfill/rebuild is optional and gated.
        $this->assertTrue($files[2]['optional']);
        $this->assertSame('only_after_repository_and_projection_tests_exist', $files[2]['gate']);
    }

    /**
     * Doc "Reservation Events Table": exactly the twelve required columns, and
     * `event_hash` is the ONLY unique index among the six required indexes.
     */
    public function test_events_table_columns_and_unique_event_hash_index(): void
    {
        $service = $this->service();

        $this->assertSame([
            'id', 'reservation_id', 'packet_id', 'event_type', 'actor_id',
            'session_id', 'packet_hash', 'allowed_files_hash', 'previous_event_hash',
            'event_hash', 'payload', 'created_at',
        ], $service->columnsFor('atlas_self_construction_reservation_events'));

        $indexes = $service->indexesFor('atlas_self_construction_reservation_events');
        $this->assertSame(['reservation_id', 'packet_id', 'event_type', 'session_id', 'event_hash', 'created_at'], array_keys($indexes));

        // event_hash is unique; nothing else is.
        $this->assertTrue($indexes['event_hash']);
        $this->assertSame('event_hash', $service->uniqueIndexFor('atlas_self_construction_reservation_events'));
        $this->assertFalse($indexes['created_at']);
        $this->assertFalse($indexes['reservation_id']);
    }

    /**
     * Doc "Reservations Projection Table": the named columns plus timestamps
     * (created_at + updated_at), and the active packet claim guard is the ONLY
     * unique index.
     */
    public function test_reservations_table_columns_and_unique_claim_guard(): void
    {
        $service = $this->service();

        $columns = $service->columnsFor('atlas_self_construction_reservations');
        // Named columns from the doc are all present...
        foreach (['reservation_id', 'packet_id', 'owner_id', 'session_id', 'state', 'lease_expires_at', 'completed_at', 'released_at', 'blocker_reason'] as $col) {
            $this->assertContains($col, $columns, "projection must declare column {$col}");
        }
        // ...and "timestamps" expands to created_at + updated_at.
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);

        // The active packet claim guard is the single unique index.
        $this->assertSame('active_packet_claim_guard', $service->uniqueIndexFor('atlas_self_construction_reservations'));
        $indexes = $service->indexesFor('atlas_self_construction_reservations');
        $this->assertTrue($indexes['active_packet_claim_guard']);
        $this->assertFalse($indexes['state']);
    }

    /**
     * Doc rollback rule "rollback drops projection before events": rollbackOrder
     * is the strict reverse of createOrder, and assertRollbackSafe rejects any
     * order that would drop the events table before the projection.
     */
    public function test_rollback_drops_projection_before_events(): void
    {
        $service = $this->service();

        $this->assertSame(
            ['atlas_self_construction_reservation_events', 'atlas_self_construction_reservations'],
            $service->createOrder(),
        );
        // Reverse: projection dropped first, events last.
        $this->assertSame(
            ['atlas_self_construction_reservations', 'atlas_self_construction_reservation_events'],
            $service->rollbackOrder(),
        );

        // The documented (safe) order passes the guard...
        $this->assertSame([], $service->assertRollbackSafe($service->rollbackOrder()));

        // ...but dropping events before the projection is rejected fail-closed.
        $unsafe = $service->assertRollbackSafe([
            'atlas_self_construction_reservation_events',
            'atlas_self_construction_reservations',
        ]);
        $this->assertContains('rollback_drops_events_before_projection', $unsafe);
    }

    /**
     * validateProposedMigration fails CLOSED: a draft events migration missing a
     * column, missing an index and getting event_hash uniqueness wrong reports
     * every defect; the exact blueprint validates clean.
     */
    public function test_validate_proposed_migration_fails_closed_on_drift(): void
    {
        $service = $this->service();

        $bad = $service->validateProposedMigration([
            'table' => 'atlas_self_construction_reservation_events',
            'columns' => ['id', 'reservation_id', 'packet_id'], // dropped many, incl. event_hash
            'indexes' => [
                'reservation_id' => false,
                'packet_id' => false,
                'event_type' => false,
                'session_id' => false,
                'event_hash' => false, // wrong: must be unique
                'created_at' => false,
            ],
        ]);
        $this->assertFalse($bad['ok']);
        $this->assertContains('event_hash', $bad['missing_columns']);
        $this->assertContains('payload', $bad['missing_columns']);
        $this->assertContains('event_hash', $bad['wrong_uniqueness']);
        $this->assertContains('missing_column', $bad['reasons']);
        $this->assertContains('wrong_index_uniqueness', $bad['reasons']);

        // The exact blueprint passes.
        $good = $service->validateProposedMigration([
            'table' => 'atlas_self_construction_reservation_events',
            'columns' => $service->columnsFor('atlas_self_construction_reservation_events'),
            'indexes' => $service->indexesFor('atlas_self_construction_reservation_events'),
        ]);
        $this->assertTrue($good['ok']);
        $this->assertSame([], $good['reasons']);
    }

    /**
     * The doc's hard non-execution guarantee: blueprint generation "cannot create
     * migrations, write storage, persist claims or dispatch work." Every result
     * keeps the four guarantee keys false, and assertGuaranteeHeld confirms it.
     */
    public function test_non_execution_guarantee_holds_on_every_path(): void
    {
        $service = $this->service();

        $blueprint = $service->blueprint();
        $valid = $service->validateProposedMigration([
            'table' => 'atlas_self_construction_reservations',
            'columns' => $service->columnsFor('atlas_self_construction_reservations'),
            'indexes' => $service->indexesFor('atlas_self_construction_reservations'),
        ]);
        $unknown = $service->validateProposedMigration(['table' => 'some_other_table']);

        foreach ([$blueprint, $valid, $unknown] as $packet) {
            $this->assertFalse($packet['migration_created']);
            $this->assertFalse($packet['is_execution']);
            $this->assertSame([
                'migrations_created' => false,
                'storage_writes_performed' => false,
                'claims_persisted' => false,
                'work_dispatched' => false,
            ], $packet['guarantee']);
        }

        $this->assertSame([], $service->assertGuaranteeHeld([$blueprint, $valid, $unknown]));

        // The blueprint also carries the seven required tests verbatim.
        $this->assertContains('rollback_drops_projection_before_events', $blueprint['required_tests']);
        $this->assertContains('event_hash_is_unique', $blueprint['required_tests']);
        $this->assertCount(7, $blueprint['required_tests']);
    }
}
