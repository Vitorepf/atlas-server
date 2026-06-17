<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE U7 — CLARIFICATION ROUTING columns on the U5 queue. A deterministic router (reason + family +
 * recurrence) stamps each pending clarification with a SURFACE (operator_inbox | operator_urgent) and a
 * PRIORITY (low | normal | high), so the operator's clarification inbox can sort/filter/route in SQL
 * instead of treating every abstention the same. Additive + idempotent (hasColumn guards). Default OFF =>
 * the router never runs => these columns stay NULL => byte-identical queue behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            return;
        }
        Schema::table('atlas_loop_clarification_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_loop_clarification_requests', 'surface')) {
                $table->string('surface')->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_loop_clarification_requests', 'priority')) {
                $table->string('priority')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            return;
        }
        Schema::table('atlas_loop_clarification_requests', function (Blueprint $table): void {
            foreach (['surface', 'priority'] as $col) {
                if (Schema::hasColumn('atlas_loop_clarification_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
