<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Illuminate\Support\Facades\Schema;

/**
 * Probes the Agent Control Plane runtime database schema: migration path,
 * table existence, required columns, and overall readiness.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * Pure static methods — no instance state.
 */
final class ReadinessAgentControlPlaneSchemaProbe
{
    public static function migration(): string
    {
        return 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php';
    }

    /**
     * @return array<string, bool>
     */
    public static function tables(): array
    {
        return [
            'atlas_self_construction_agent_runs' => Schema::hasTable('atlas_self_construction_agent_runs'),
            'atlas_self_construction_agent_heartbeats' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
            'atlas_self_construction_agent_cost_events' => Schema::hasTable('atlas_self_construction_agent_cost_events'),
            'atlas_self_construction_agent_work_products' => Schema::hasTable('atlas_self_construction_agent_work_products'),
            'atlas_self_construction_agent_wakeup_items' => Schema::hasTable('atlas_self_construction_agent_wakeup_items'),
            'atlas_self_construction_agent_dispatch_receipts' => Schema::hasTable('atlas_self_construction_agent_dispatch_receipts'),
        ];
    }

    /**
     * Required columns per table. Each entry maps table_name => list of required column names.
     *
     * @return array<string, list<string>>
     */
    public static function requiredColumns(): array
    {
        return [
            'atlas_self_construction_agent_runs' => [
                'id', 'task_packet_id', 'client_id', 'lease_id',
                'status', 'objective_digest', 'started_at', 'updated_at',
            ],
            'atlas_self_construction_agent_heartbeats' => [
                'id', 'client_id', 'last_heartbeat_at', 'lease_id', 'status',
            ],
        ];
    }

    /**
     * Check whether every required column exists in a supplied "schema" map.
     * The map format: table_name => list<string> (actual column names).
     * Returns true when every required column for every table is present.
     *
     * @param  array<string, list<string>>  $actualColumns  table_name => list of actual column names
     * @return array{ready:bool, missing:list<string>}
     */
    public static function columnsReady(array $actualColumns): array
    {
        $missing = [];
        foreach (self::requiredColumns() as $table => $cols) {
            $existing = array_flip($actualColumns[$table] ?? []);
            foreach ($cols as $col) {
                if (! isset($existing[$col])) {
                    $missing[] = "{$table}.{$col}";
                }
            }
        }
        sort($missing, SORT_STRING);

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * Check whether a migration marker exists (e.g. by inspecting if a
     * migration-level sentinel row or file is present).
     *
     * @param  list<string>  $appliedMigrations  list of migration filenames that have run
     * @param  string  $requiredMigration      the migration file that must be present
     * @return array{ready:bool, applied:bool}
     */
    public static function migrationMarkerReady(array $appliedMigrations, string $requiredMigration = ''): array
    {
        $required = $requiredMigration !== '' ? $requiredMigration : self::migration();
        $applied = in_array($required, $appliedMigrations, true);

        return ['ready' => $applied, 'applied' => $applied];
    }

    /**
     * @param  array<string, bool>|null  $tables
     */
    public static function schemaReady(?array $tables = null): bool
    {
        $tables ??= self::tables();

        return ! in_array(false, $tables, true);
    }
}
