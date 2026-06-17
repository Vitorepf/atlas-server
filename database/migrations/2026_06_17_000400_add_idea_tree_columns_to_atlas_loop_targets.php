<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARBOR-GRAFT T1 — IDEA-TREE columns on the discovery target (sibling competition + path-to-root).
 *
 * Arbor's real substrate is a persistent hypothesis TREE where siblings compete under a parent and a
 * distilled insight propagates up the path. Atlas's discovery queue is flat (AtlasLoopTarget). These
 * additive columns let a target carry a parent edge, a depth tier, a structured hypothesis and a
 * narrative node-insight — the candidate-lineage substrate the SELECT adjuster (SEL1) and the
 * constraints-block (CB1) read.
 *
 * Additive + idempotent (hasTable/hasColumn guards). Every column is nullable / depth defaults 0, so
 * with no producer writing tree edges the rows are a byte-identical flat ledger == today. The narrative
 * columns (hypothesis / node_insight / tree_status / node_kind) are ADVISORY by canon and are walled off
 * from every gate by the AtlasLoopAdvisoryFirewallTest — they may never certify.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_targets')) {
            return;
        }
        Schema::table('atlas_loop_targets', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_loop_targets', 'parent_target_id')) {
                $table->uuid('parent_target_id')->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_loop_targets', 'depth')) {
                $table->unsignedInteger('depth')->default(0);
            }
            if (! Schema::hasColumn('atlas_loop_targets', 'node_kind')) {
                // direction (depth-1 axis) | implementation (depth-2+ concrete) — advisory typing only.
                $table->string('node_kind')->nullable();
            }
            if (! Schema::hasColumn('atlas_loop_targets', 'tree_status')) {
                // pending | running | done | needs_retry | merged | pruned — advisory mirror, never a gate.
                $table->string('tree_status')->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_loop_targets', 'hypothesis')) {
                $table->json('hypothesis')->nullable();
            }
            if (! Schema::hasColumn('atlas_loop_targets', 'node_insight')) {
                $table->json('node_insight')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_loop_targets')) {
            return;
        }
        Schema::table('atlas_loop_targets', function (Blueprint $table): void {
            foreach (['parent_target_id', 'depth', 'node_kind', 'tree_status', 'hypothesis', 'node_insight'] as $col) {
                if (Schema::hasColumn('atlas_loop_targets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
