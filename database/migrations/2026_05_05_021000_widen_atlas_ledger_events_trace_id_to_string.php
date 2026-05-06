<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE atlas_ledger_events ALTER COLUMN trace_id TYPE varchar(80) USING trace_id::text');

            return;
        }

        Schema::table('atlas_ledger_events', function ($table): void {
            $table->string('trace_id', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE atlas_ledger_events
                ALTER COLUMN trace_id TYPE uuid
                USING CASE
                    WHEN trace_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                    THEN trace_id::uuid
                    ELSE NULL
                END
            SQL);

            return;
        }

        Schema::table('atlas_ledger_events', function ($table): void {
            $table->uuid('trace_id')->nullable()->change();
        });
    }
};
