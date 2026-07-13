<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_stream_events') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('ALTER TABLE ai_stream_events DROP CONSTRAINT IF EXISTS ai_stream_events_event_type_check');
            DB::statement(<<<'SQL'
                ALTER TABLE ai_stream_events
                ADD CONSTRAINT ai_stream_events_event_type_check
                CHECK (event_type IN (
                    'lifecycle', 'permission', 'progress', 'stdout', 'stderr',
                    'token', 'response', 'error', 'tool', 'thinking'
                ))
            SQL);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_stream_events') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::table('ai_stream_events')
                ->whereIn('event_type', ['tool', 'thinking'])
                ->update(['event_type' => 'progress']);
            DB::statement('ALTER TABLE ai_stream_events DROP CONSTRAINT IF EXISTS ai_stream_events_event_type_check');
            DB::statement(<<<'SQL'
                ALTER TABLE ai_stream_events
                ADD CONSTRAINT ai_stream_events_event_type_check
                CHECK (event_type IN (
                    'lifecycle', 'permission', 'progress', 'stdout', 'stderr',
                    'token', 'response', 'error'
                ))
            SQL);
        });
    }
};
