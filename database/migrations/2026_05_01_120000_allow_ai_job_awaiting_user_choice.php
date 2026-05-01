<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            DROP CONSTRAINT IF EXISTS ai_jobs_status_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_status_check
            CHECK (status IN (
                'queued',
                'processing',
                'succeeded',
                'failed',
                'cancelled',
                'awaiting_user_choice'
            ));
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            DROP CONSTRAINT IF EXISTS ai_jobs_status_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_status_check
            CHECK (status IN (
                'queued',
                'processing',
                'succeeded',
                'failed',
                'cancelled'
            ));
        SQL);
    }
};
