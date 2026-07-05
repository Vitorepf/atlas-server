<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessAgentControlPlaneSchemaProbe;
use Tests\TestCase;

class ReadinessAgentControlPlaneSchemaProbeTest extends TestCase
{
    public function test_migration_returns_expected_path(): void
    {
        $migration = ReadinessAgentControlPlaneSchemaProbe::migration();

        self::assertStringContainsString('create_atlas_self_construction_agent_control_plane_tables', $migration);
        self::assertStringStartsWith('database/migrations/', $migration);
        self::assertStringEndsWith('.php', $migration);
    }

    public function test_tables_returns_all_expected_table_keys(): void
    {
        $tables = ReadinessAgentControlPlaneSchemaProbe::tables();

        $expectedKeys = [
            'atlas_self_construction_agent_runs',
            'atlas_self_construction_agent_heartbeats',
            'atlas_self_construction_agent_cost_events',
            'atlas_self_construction_agent_work_products',
            'atlas_self_construction_agent_wakeup_items',
            'atlas_self_construction_agent_dispatch_receipts',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $tables, "tables() must include: {$key}");
        }
    }

    public function test_tables_returns_boolean_values(): void
    {
        $tables = ReadinessAgentControlPlaneSchemaProbe::tables();

        foreach ($tables as $table => $exists) {
            self::assertIsBool($exists, "table {$table} must map to boolean");
        }
    }

    public function test_schema_ready_returns_true_when_all_tables_exist(): void
    {
        $tables = [
            'table_a' => true,
            'table_b' => true,
            'table_c' => true,
        ];

        self::assertTrue(ReadinessAgentControlPlaneSchemaProbe::schemaReady($tables));
    }

    public function test_schema_ready_returns_false_when_any_table_missing(): void
    {
        $tables = [
            'table_a' => true,
            'table_b' => false,
            'table_c' => true,
        ];

        self::assertFalse(ReadinessAgentControlPlaneSchemaProbe::schemaReady($tables));
    }

    public function test_schema_ready_returns_true_for_empty_tables_array(): void
    {
        self::assertTrue(ReadinessAgentControlPlaneSchemaProbe::schemaReady([]));
    }

    public function test_schema_ready_returns_false_when_all_missing(): void
    {
        $tables = [
            'table_a' => false,
            'table_b' => false,
        ];

        self::assertFalse(ReadinessAgentControlPlaneSchemaProbe::schemaReady($tables));
    }

    public function test_schema_ready_defaults_to_live_probe_when_no_arg(): void
    {
        // Calls self::tables() internally — should return a boolean
        $result = ReadinessAgentControlPlaneSchemaProbe::schemaReady();

        self::assertIsBool($result);
    }

    // --- requiredColumns & columnsReady --------------------------------

    public function test_required_columns_returns_expected_tables(): void
    {
        $cols = ReadinessAgentControlPlaneSchemaProbe::requiredColumns();

        $this->assertArrayHasKey('atlas_self_construction_agent_runs', $cols);
        $this->assertArrayHasKey('atlas_self_construction_agent_heartbeats', $cols);
    }

    public function test_columns_ready_returns_ready_when_all_columns_present(): void
    {
        $result = ReadinessAgentControlPlaneSchemaProbe::columnsReady([
            'atlas_self_construction_agent_runs' => [
                'id', 'task_packet_id', 'client_id', 'lease_id',
                'status', 'objective_digest', 'started_at', 'updated_at',
            ],
            'atlas_self_construction_agent_heartbeats' => [
                'id', 'client_id', 'last_heartbeat_at', 'lease_id', 'status',
            ],
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['missing']);
    }

    public function test_columns_ready_returns_not_ready_when_column_missing(): void
    {
        $result = ReadinessAgentControlPlaneSchemaProbe::columnsReady([
            'atlas_self_construction_agent_runs' => ['id', 'task_packet_id'], // missing lease_id, etc.
            'atlas_self_construction_agent_heartbeats' => ['id', 'client_id'], // missing last_heartbeat_at, etc.
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['missing']);
        $this->assertContains('atlas_self_construction_agent_runs.lease_id', $result['missing']);
        $this->assertContains('atlas_self_construction_agent_heartbeats.last_heartbeat_at', $result['missing']);
    }

    public function test_columns_ready_returns_not_ready_when_table_missing_from_schema(): void
    {
        $result = ReadinessAgentControlPlaneSchemaProbe::columnsReady([]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['missing']);
    }

    public function test_columns_ready_is_deterministic(): void
    {
        $schema = [
            'atlas_self_construction_agent_runs' => [
                'id', 'task_packet_id', 'client_id', 'lease_id',
                'status', 'objective_digest', 'started_at', 'updated_at',
            ],
            'atlas_self_construction_agent_heartbeats' => [
                'id', 'client_id', 'last_heartbeat_at', 'lease_id', 'status',
            ],
        ];

        $a = ReadinessAgentControlPlaneSchemaProbe::columnsReady($schema);
        $b = ReadinessAgentControlPlaneSchemaProbe::columnsReady($schema);

        $this->assertSame($a, $b);
    }

    // --- migrationMarkerReady ------------------------------------------

    public function test_migration_marker_ready_returns_true_when_applied(): void
    {
        $migration = ReadinessAgentControlPlaneSchemaProbe::migration();

        $result = ReadinessAgentControlPlaneSchemaProbe::migrationMarkerReady(
            [$migration, 'some_other_migration.php'],
        );

        $this->assertTrue($result['ready']);
        $this->assertTrue($result['applied']);
    }

    public function test_migration_marker_ready_returns_false_when_not_applied(): void
    {
        $result = ReadinessAgentControlPlaneSchemaProbe::migrationMarkerReady(
            ['some_other_migration.php'],
        );

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['applied']);
    }

    public function test_migration_marker_ready_is_deterministic(): void
    {
        $a = ReadinessAgentControlPlaneSchemaProbe::migrationMarkerReady([]);
        $b = ReadinessAgentControlPlaneSchemaProbe::migrationMarkerReady([]);

        $this->assertSame($a, $b);
    }
}
