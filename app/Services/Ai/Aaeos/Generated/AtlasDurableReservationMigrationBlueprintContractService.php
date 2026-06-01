<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Migration Blueprint Contract
 * — pure, deterministic, READ-ONLY surface.
 *
 * This emits the EXACT future migration blueprint for durable packet
 * reservations: the ordered migration files, the two tables' required columns
 * and indexes, the uniqueness rules and the rollback order. Per the doc, "it is
 * not a migration and must not touch `database/migrations`"; generating this
 * blueprint is itself read-only. Every result therefore keeps the four
 * non-execution guarantee keys false — emitting the plan is never the act of
 * running it.
 *
 * It is the migration-planning sibling of
 * {@see AtlasDurableReservationStorageSchemaService} (which owns the schema
 * fields + claim evaluation) and
 * {@see AtlasDurableReservationLedgerImplementationPlanService} (which owns the
 * implementation steps). Here we own ONLY the deterministic migration blueprint:
 * file names, per-table column/index specs, and — critically — the rollback
 * drop-ordering invariant.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Future Migration Files" => the ordered creation plan is exactly:
 *       1. create_atlas_self_construction_reservation_events_table;
 *       2. create_atlas_self_construction_reservations_table;
 *       3. (optional) backfill/rebuild command — ONLY after repository and
 *          projection tests exist, so it is flagged optional + gated.
 *   - "Reservation Events Table" => the twelve required columns and the six
 *     required indexes, with `event_hash` the ONLY unique index.
 *   - "Reservations Projection Table" => the named columns plus timestamps and
 *     the six required indexes, with the active packet claim guard the ONLY
 *     unique index.
 *   - Rollback rule "rollback drops projection before events" => rollbackOrder()
 *     literally REVERSES the create order, so the projection table is dropped
 *     strictly before the events table. assertRollbackSafe() rejects any order
 *     that would drop events first (which would orphan the projection's source).
 *   - "Required Tests" => the seven documented test obligations, surfaced as data
 *     so future runtime cannot silently drop one.
 *   - Completion Criteria => a deterministic read-only migration blueprint that
 *     "future implementation can convert into Laravel migrations without
 *     changing scope, naming or invariants", plus assertGuaranteeHeld().
 *
 * validateProposedMigration() lets future runtime check a draft migration spec
 * against this blueprint: it fails CLOSED, reporting every missing column, every
 * missing index, every wrong-uniqueness index and any scope/naming drift, rather
 * than silently accepting a divergent migration.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md
 */
final class AtlasDurableReservationMigrationBlueprintContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_migration_blueprint_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_migration_blueprint_contract';

    // --- Doc "Future Migration Files" / table names --------------------------
    public const TABLE_EVENTS = 'atlas_self_construction_reservation_events';
    public const TABLE_RESERVATIONS = 'atlas_self_construction_reservations';

    public const MIGRATION_EVENTS = 'create_atlas_self_construction_reservation_events_table';
    public const MIGRATION_RESERVATIONS = 'create_atlas_self_construction_reservations_table';
    public const MIGRATION_BACKFILL = 'rebuild_atlas_self_construction_reservations_projection_from_events';

    /**
     * Doc "Reservation Events Table" — required columns, in documented order.
     *
     * @var list<string>
     */
    public const EVENTS_COLUMNS = [
        'id',
        'reservation_id',
        'packet_id',
        'event_type',
        'actor_id',
        'session_id',
        'packet_hash',
        'allowed_files_hash',
        'previous_event_hash',
        'event_hash',
        'payload',
        'created_at',
    ];

    /**
     * Doc "Reservation Events Table" — required indexes. `event_hash` is the
     * ONLY unique index (the append-only event log must not duplicate a hash).
     *
     * @var array<string,bool> column => isUnique
     */
    public const EVENTS_INDEXES = [
        'reservation_id' => false,
        'packet_id' => false,
        'event_type' => false,
        'session_id' => false,
        'event_hash' => true,
        'created_at' => false,
    ];

    /**
     * Doc "Reservations Projection Table" — required columns. "timestamps" in the
     * doc expands to created_at + updated_at (Laravel's $table->timestamps()).
     *
     * @var list<string>
     */
    public const RESERVATIONS_COLUMNS = [
        'id',
        'reservation_id',
        'packet_id',
        'owner_id',
        'session_id',
        'state',
        'packet_hash',
        'allowed_files_hash',
        'lease_expires_at',
        'completed_at',
        'released_at',
        'blocker_reason',
        'created_at',
        'updated_at',
    ];

    /**
     * Doc "Reservations Projection Table" — required indexes. The "unique active
     * packet claim guard" is the ONLY unique index: it is what guarantees a
     * packet can have at most one live claim. Named here as a closed token so
     * runtime cannot rename the invariant.
     *
     * @var array<string,bool> indexName => isUnique
     */
    public const RESERVATIONS_INDEXES = [
        'active_packet_claim_guard' => true,
        'packet_id' => false,
        'owner_session' => false,
        'state' => false,
        'lease_expires_at' => false,
        'allowed_files_hash' => false,
    ];

    /**
     * Doc "Required Tests" — the seven obligations future runtime must satisfy
     * before the migrations are considered proven. Emitted as data.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'migrations_create_both_tables_with_required_columns',
        'event_hash_is_unique',
        'active_packet_claim_uniqueness_enforced_by_repository_transaction',
        'lease_expiry_is_queryable',
        'projection_can_be_rebuilt_from_events',
        'rollback_drops_projection_before_events',
        'blueprint_command_does_not_create_migrations_or_write_storage',
    ];

    /** Validation reason tokens (closed set). */
    public const REASON_MISSING_COLUMN = 'missing_column';
    public const REASON_MISSING_INDEX = 'missing_index';
    public const REASON_WRONG_UNIQUENESS = 'wrong_index_uniqueness';
    public const REASON_UNKNOWN_TABLE = 'unknown_table';
    public const REASON_TABLE_NAME_DRIFT = 'table_name_drift';
    public const REASON_ROLLBACK_ORDER_UNSAFE = 'rollback_drops_events_before_projection';

    /**
     * Non-execution guarantee keys (doc: blueprint generation "cannot create
     * migrations, write storage, persist claims or dispatch work"). Every result
     * keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'migrations_created',
        'storage_writes_performed',
        'claims_persisted',
        'work_dispatched',
    ];

    /**
     * The four documented non-execution guarantee keys, all forced false.
     *
     * @return array<string,false>
     */
    public function guarantee(): array
    {
        $out = [];
        foreach (self::GUARANTEE_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Doc "Future Migration Files": the ordered creation plan. The events table
     * MUST be created before the projection table (the projection is rebuilt FROM
     * events), and the backfill/rebuild command is optional and gated on
     * repository + projection tests existing.
     *
     * @return list<array{order:int,name:string,creates:?string,optional:bool,gate:?string}>
     */
    public function migrationFiles(): array
    {
        return [
            [
                'order' => 1,
                'name' => self::MIGRATION_EVENTS,
                'creates' => self::TABLE_EVENTS,
                'optional' => false,
                'gate' => null,
            ],
            [
                'order' => 2,
                'name' => self::MIGRATION_RESERVATIONS,
                'creates' => self::TABLE_RESERVATIONS,
                'optional' => false,
                'gate' => null,
            ],
            [
                'order' => 3,
                'name' => self::MIGRATION_BACKFILL,
                'creates' => null,
                'optional' => true,
                'gate' => 'only_after_repository_and_projection_tests_exist',
            ],
        ];
    }

    /**
     * The forward (up) creation order of the two real tables.
     *
     * @return list<string>
     */
    public function createOrder(): array
    {
        return [self::TABLE_EVENTS, self::TABLE_RESERVATIONS];
    }

    /**
     * Doc rollback rule: "rollback drops projection before events." The drop
     * order is the strict REVERSE of the create order, so the projection
     * (atlas_self_construction_reservations) is dropped first and the events log
     * last.
     *
     * @return list<string>
     */
    public function rollbackOrder(): array
    {
        return array_values(array_reverse($this->createOrder()));
    }

    /**
     * Enforce the rollback invariant on a proposed drop order: the projection
     * table must be dropped strictly BEFORE the events table. Returns the list of
     * violated reasons (empty = safe). Fails closed.
     *
     * @param list<string> $proposedDropOrder
     * @return list<string>
     */
    public function assertRollbackSafe(array $proposedDropOrder): array
    {
        $projectionPos = array_search(self::TABLE_RESERVATIONS, $proposedDropOrder, true);
        $eventsPos = array_search(self::TABLE_EVENTS, $proposedDropOrder, true);

        // Both tables must be present in the drop plan.
        if ($projectionPos === false || $eventsPos === false) {
            return [self::REASON_ROLLBACK_ORDER_UNSAFE];
        }

        // Projection must come before events; otherwise events would be dropped
        // while the projection (its derived read model) still references them.
        if ((int) $projectionPos > (int) $eventsPos) {
            return [self::REASON_ROLLBACK_ORDER_UNSAFE];
        }

        return [];
    }

    /**
     * Required columns for a table name. Throws for an unknown table so callers
     * cannot silently get an empty contract.
     *
     * @return list<string>
     */
    public function columnsFor(string $table): array
    {
        return match ($table) {
            self::TABLE_EVENTS => self::EVENTS_COLUMNS,
            self::TABLE_RESERVATIONS => self::RESERVATIONS_COLUMNS,
            default => [],
        };
    }

    /**
     * Required indexes (name => isUnique) for a table name.
     *
     * @return array<string,bool>
     */
    public function indexesFor(string $table): array
    {
        return match ($table) {
            self::TABLE_EVENTS => self::EVENTS_INDEXES,
            self::TABLE_RESERVATIONS => self::RESERVATIONS_INDEXES,
            default => [],
        };
    }

    /**
     * The unique-index name for a table (the one invariant-bearing index): the
     * unique `event_hash` for events, the active packet claim guard for the
     * projection.
     */
    public function uniqueIndexFor(string $table): ?string
    {
        foreach ($this->indexesFor($table) as $index => $isUnique) {
            if ($isUnique) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Validate a proposed/draft migration spec for ONE table against this
     * blueprint. Fails closed: reports every missing column, every missing index
     * and every index whose uniqueness differs from the contract, plus
     * table-name drift. Returns a structured verdict; `ok` is true only when the
     * proposal matches the blueprint exactly (it may add nothing it must, but it
     * may not omit or weaken anything).
     *
     * @param array{
     *   table?:string,
     *   columns?:list<string>,
     *   indexes?:array<string,bool>
     * } $proposed
     * @return array{
     *   surface:string, schema:string,
     *   table:?string, ok:bool,
     *   missing_columns:list<string>,
     *   missing_indexes:list<string>,
     *   wrong_uniqueness:list<string>,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   migration_created:false, is_execution:false
     * }
     */
    public function validateProposedMigration(array $proposed = []): array
    {
        $table = is_string($proposed['table'] ?? null) ? $proposed['table'] : null;
        $required = $table !== null ? $this->columnsFor($table) : [];
        $requiredIndexes = $table !== null ? $this->indexesFor($table) : [];

        $reasons = [];
        if ($table === null || $required === []) {
            $reasons[] = self::REASON_UNKNOWN_TABLE;

            return $this->validationResult($table, false, [], [], [], $reasons);
        }

        // Reject a renamed table that is not one of the two canonical names.
        if ($table !== self::TABLE_EVENTS && $table !== self::TABLE_RESERVATIONS) {
            $reasons[] = self::REASON_TABLE_NAME_DRIFT;
        }

        $proposedColumns = $this->stringList($proposed['columns'] ?? []);
        $proposedIndexes = is_array($proposed['indexes'] ?? null) ? $proposed['indexes'] : [];

        $missingColumns = [];
        foreach ($required as $col) {
            if (! in_array($col, $proposedColumns, true)) {
                $missingColumns[] = $col;
            }
        }

        $missingIndexes = [];
        $wrongUniqueness = [];
        foreach ($requiredIndexes as $index => $isUnique) {
            if (! array_key_exists($index, $proposedIndexes)) {
                $missingIndexes[] = $index;

                continue;
            }
            if (($proposedIndexes[$index] === true) !== $isUnique) {
                $wrongUniqueness[] = $index;
            }
        }

        if ($missingColumns !== []) {
            $reasons[] = self::REASON_MISSING_COLUMN;
        }
        if ($missingIndexes !== []) {
            $reasons[] = self::REASON_MISSING_INDEX;
        }
        if ($wrongUniqueness !== []) {
            $reasons[] = self::REASON_WRONG_UNIQUENESS;
        }

        return $this->validationResult(
            $table,
            $reasons === [],
            $missingColumns,
            $missingIndexes,
            $wrongUniqueness,
            $reasons,
        );
    }

    /**
     * The full read-only migration BLUEPRINT future runtime converts into Laravel
     * migrations "without changing scope, naming or invariants" — emitted as data
     * so every name, column, index and ordering is checkable, never guessed.
     *
     * @return array{
     *   surface:string, schema:string,
     *   migration_files:list<array{order:int,name:string,creates:?string,optional:bool,gate:?string}>,
     *   tables:array<string,array{columns:list<string>,indexes:array<string,bool>,unique_index:?string}>,
     *   create_order:list<string>,
     *   rollback_order:list<string>,
     *   rollback_rule:string,
     *   required_tests:list<string>,
     *   guarantee:array<string,false>,
     *   migration_created:false, is_execution:false
     * }
     */
    public function blueprint(): array
    {
        $tables = [];
        foreach ($this->createOrder() as $table) {
            $tables[$table] = [
                'columns' => $this->columnsFor($table),
                'indexes' => $this->indexesFor($table),
                'unique_index' => $this->uniqueIndexFor($table),
            ];
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'migration_files' => $this->migrationFiles(),
            'tables' => $tables,
            'create_order' => $this->createOrder(),
            'rollback_order' => $this->rollbackOrder(),
            'rollback_rule' => 'drop_projection_before_events',
            'required_tests' => self::REQUIRED_TESTS,
            'guarantee' => $this->guarantee(),
            'migration_created' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Prove that no result flipped a non-execution guarantee key (or the two
     * restated guarantees) to a truthy value. Returns "surface.key" violations
     * (empty = intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            foreach (['migration_created', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Assemble a uniform validation result.
     *
     * @param list<string> $missingColumns
     * @param list<string> $missingIndexes
     * @param list<string> $wrongUniqueness
     * @param list<string> $reasons
     * @return array{
     *   surface:string, schema:string,
     *   table:?string, ok:bool,
     *   missing_columns:list<string>,
     *   missing_indexes:list<string>,
     *   wrong_uniqueness:list<string>,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   migration_created:false, is_execution:false
     * }
     */
    private function validationResult(
        ?string $table,
        bool $ok,
        array $missingColumns,
        array $missingIndexes,
        array $wrongUniqueness,
        array $reasons,
    ): array {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'table' => $table,
            'ok' => $ok,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
            'wrong_uniqueness' => $wrongUniqueness,
            'reasons' => $reasons,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'migration_created' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Coerce a value into a list of strings (defensive against malformed input).
     *
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
