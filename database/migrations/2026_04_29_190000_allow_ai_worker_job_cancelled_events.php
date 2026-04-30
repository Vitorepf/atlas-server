<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            DROP CONSTRAINT IF EXISTS ai_worker_events_event_type_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            ADD CONSTRAINT ai_worker_events_event_type_check
            CHECK (event_type IN (
                'worker_started',
                'worker_heartbeat',
                'worker_stopped',
                'job_claimed',
                'job_succeeded',
                'job_failed',
                'job_requeued',
                'job_cancelled',
                'provider_unavailable',
                'auth_expired',
                'rate_limited',
                'timeout',
                'cli_error',
                'health_check'
            ));
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            DROP CONSTRAINT IF EXISTS ai_worker_events_event_type_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            ADD CONSTRAINT ai_worker_events_event_type_check
            CHECK (event_type IN (
                'worker_started',
                'worker_heartbeat',
                'worker_stopped',
                'job_claimed',
                'job_succeeded',
                'job_failed',
                'job_requeued',
                'provider_unavailable',
                'auth_expired',
                'rate_limited',
                'timeout',
                'cli_error',
                'health_check'
            ));
        SQL);
    }
};
