<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        // sqlite ignores varchar length, so this is a pgsql-only widening.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_marketing_vsl_assets ALTER COLUMN niche TYPE varchar(300)');
        DB::statement('ALTER TABLE ai_marketing_vsl_assets ALTER COLUMN sub_niche TYPE varchar(400)');
        DB::statement('ALTER TABLE ai_marketing_vsl_assets ALTER COLUMN awareness_level TYPE varchar(160)');
        DB::statement('ALTER TABLE ai_marketing_vsl_assets ALTER COLUMN sophistication_level TYPE varchar(80)');
    }

    public function down(): void
    {
        // Widening only — intentionally no rollback.
    }
};
