<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_strategy_rivals_cases')
            || Schema::hasColumn('atlas_strategy_rivals_cases', 'source_hash')) {
            return;
        }

        Schema::table('atlas_strategy_rivals_cases', function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->unique()->after('horizon_days');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_strategy_rivals_cases')
            || ! Schema::hasColumn('atlas_strategy_rivals_cases', 'source_hash')) {
            return;
        }

        Schema::table('atlas_strategy_rivals_cases', function (Blueprint $table): void {
            $table->dropUnique(['source_hash']);
            $table->dropColumn('source_hash');
        });
    }
};
