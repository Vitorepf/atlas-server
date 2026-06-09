<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP-815 B1 — seekable composite indexes for the code-graph traversal hot path.
 *
 * edgesTouching()/adjacency() filter `world_model_id` + (`from_node_id` OR `to_node_id`).
 * The table previously had only single-column indexes, so the planner could not seek the
 * combined predicate and degraded toward a per-model edge scan. These two composite
 * indexes let each UNION side seek directly. Additive + idempotent (cross-driver).
 */
return new class extends Migration
{
    private const TABLE = 'ai_codebase_world_model_edges';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addIndex('idx_wm_edges_wm_from_type', ['world_model_id', 'from_node_id', 'edge_type']);
        $this->addIndex('idx_wm_edges_wm_to_type', ['world_model_id', 'to_node_id', 'edge_type']);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['idx_wm_edges_wm_from_type', 'idx_wm_edges_wm_to_type'] as $name) {
            try {
                Schema::table(self::TABLE, static function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            } catch (\Throwable) {
                // already absent — idempotent down.
            }
        }
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function addIndex(string $name, array $columns): void
    {
        try {
            Schema::table(self::TABLE, static function (Blueprint $table) use ($columns, $name): void {
                $table->index($columns, $name);
            });
        } catch (\Throwable) {
            // Index already present (re-run / partial prior apply) — idempotent.
        }
    }
};
