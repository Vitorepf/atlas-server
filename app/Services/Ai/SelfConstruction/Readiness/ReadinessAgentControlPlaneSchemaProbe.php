<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Illuminate\Support\Facades\Schema;

/**
 * Probes the Agent Control Plane runtime database schema: migration path,
 * table existence, and overall readiness.
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
     * @param  array<string, bool>|null  $tables
     */
    public static function schemaReady(?array $tables = null): bool
    {
        $tables ??= self::tables();

        return ! in_array(false, $tables, true);
    }
}
