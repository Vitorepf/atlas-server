<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDurableReservationSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionDurableReservationSection —
 * the durableReservation* projection concern extracted from the god-class
 * AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionDurableReservationSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionDurableReservationSection::class, $section);
    }

    public function test_all_18_durable_reservation_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionDurableReservationSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'durableReservation')
        );
        $this->assertGreaterThanOrEqual(
            18,
            count($publicMethods),
            'Section must expose at least 18 durableReservation* public methods'
        );

        // Spot-check well-known methods.
        foreach ([
            'durableReservationLedgerImplementationPlan',
            'durableReservationRuntimeBuildPacket',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionDurableReservationSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'durableReservationLedgerImplementationPlan',
            'durableReservationRuntimeBuildPacket',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSelfConstructionReadinessService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ref = new \ReflectionMethod($runtime, 'durableReservationSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionDurableReservationSection::class, $section);
    }

    public function test_public_durable_reservation_contracts_share_tables_states_and_read_only_authority(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ledgerPlan = $runtime->durableReservationLedgerImplementationPlan();
        $storageSchema = $runtime->durableReservationStorageSchema();
        $migrationBlueprint = $runtime->durableReservationMigrationBlueprint();
        $leaseLifecycle = $runtime->durableReservationLeaseLifecycle();
        $leaseLifecycleBlueprint = $runtime->durableReservationLeaseLifecycleBlueprint();
        $expectedTables = [
            'atlas_self_construction_reservations',
            'atlas_self_construction_reservation_events',
            'atlas_self_construction_packet_snapshots',
        ];
        $expectedStates = ['available', 'claimed', 'renewed', 'released', 'expired', 'completed', 'blocked'];

        $this->assertSame($expectedTables, array_column((array) data_get($ledgerPlan, 'plan.storage_objects', []), 'name'));
        $this->assertSame($expectedTables, array_column((array) data_get($storageSchema, 'storage_schema.tables', []), 'name'));
        $this->assertSame($expectedTables, array_column((array) data_get($migrationBlueprint, 'migration_blueprint.tables', []), 'name'));
        $this->assertSame($expectedStates, data_get($ledgerPlan, 'plan.claim_states'));
        $this->assertSame($expectedStates, data_get($storageSchema, 'storage_schema.states'));
        $this->assertSame($expectedStates, data_get($leaseLifecycle, 'lease_lifecycle.states'));
        $this->assertSame($expectedStates, data_get($leaseLifecycleBlueprint, 'lease_lifecycle_blueprint.states'));
        $this->assertContains('create_atlas_self_construction_packet_snapshots_table', data_get($migrationBlueprint, 'migration_blueprint.migration_files'));
        $this->assertContains('drop_atlas_self_construction_packet_snapshots', data_get($migrationBlueprint, 'migration_blueprint.rollback_order'));
        $this->assertContains('migration_creates_packet_snapshots_table_with_required_columns', data_get($migrationBlueprint, 'migration_blueprint.required_tests'));

        foreach ([$ledgerPlan, $storageSchema, $migrationBlueprint, $leaseLifecycle, $leaseLifecycleBlueprint] as $envelope) {
            $this->assertFalse($envelope['execution_allowed']);
            $this->assertFalse($envelope['dispatch_allowed']);
        }

        $this->assertFalse($ledgerPlan['migration_write_allowed']);
        $this->assertFalse($ledgerPlan['ledger_write_allowed']);
        foreach ([$storageSchema, $migrationBlueprint] as $envelope) {
            $this->assertFalse($envelope['migration_allowed_now']);
            $this->assertFalse($envelope['storage_write_allowed']);
        }
        $this->assertFalse($leaseLifecycle['storage_write_allowed']);
        $this->assertFalse($leaseLifecycleBlueprint['storage_write_allowed']);
    }
}
