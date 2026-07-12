<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASI-12 — additive `actor` column on `atlas_memory_entry_usages`.
 *
 * The column is optional (nullable, default null) so v1 readers stay
 * byte-identical while the v2 concentration reader can derive
 * per-actor volume normalization from it. Writers stamp
 * `interactive:{session}`, `autonomos:{worker}`, `brain`, `watchdog`, …
 * with the same label vocabulary the plan uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entry_usages')) {
            return;
        }
        if (Schema::hasColumn('atlas_memory_entry_usages', 'actor')) {
            return;
        }

        Schema::table('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->string('actor', 120)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_memory_entry_usages')) {
            return;
        }
        if (! Schema::hasColumn('atlas_memory_entry_usages', 'actor')) {
            return;
        }

        Schema::table('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->dropColumn('actor');
        });
    }
};
