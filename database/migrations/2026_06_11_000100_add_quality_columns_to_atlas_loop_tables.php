<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Evolution Loop — per-attempt metric persistence + proposal quality (AP-820 S3).
 *
 * Today the loop drops every attempt's verdict down to counters: the exploration audit
 * remembers HOW MANY scenarios passed, but not what each attempt actually scored. And
 * the proposal ledger has no slot for a graded quality verdict at all. Two nullable
 * columns close both gaps:
 *
 *  - `atlas_loop_explorations.attempt_metrics`: LEAN per-attempt records only
 *    ({scenario, passed, metric, metric_finite, diff_files, diff_lines}) — NEVER
 *    stdout/stderr/diff_text; those are provider-shaped bulk that must not leak
 *    into an app-read table.
 *  - `atlas_loop_proposals.quality`: the graded quality verdict for a certified
 *    proposal. Nullable: absence = not graded (full backwards compatibility).
 *
 * Idempotent by construction (Schema::hasColumn guards) — migrations are never
 * hand-stamped into the migrations table. This migration deliberately does NOT
 * touch the never-merge CHECK + trigger layer from 2026_06_02_000200: the
 * propose-only invariant is owned there and stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_loop_explorations') && ! Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            Schema::table('atlas_loop_explorations', function (Blueprint $table): void {
                $table->jsonb('attempt_metrics')->nullable()->after('rejected_reasons');
            });
        }

        if (Schema::hasTable('atlas_loop_proposals') && ! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            Schema::table('atlas_loop_proposals', function (Blueprint $table): void {
                $table->jsonb('quality')->nullable()->after('metric');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_explorations') && Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            Schema::table('atlas_loop_explorations', function (Blueprint $table): void {
                $table->dropColumn('attempt_metrics');
            });
        }

        if (Schema::hasTable('atlas_loop_proposals') && Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            Schema::table('atlas_loop_proposals', function (Blueprint $table): void {
                $table->dropColumn('quality');
            });
        }
    }
};
