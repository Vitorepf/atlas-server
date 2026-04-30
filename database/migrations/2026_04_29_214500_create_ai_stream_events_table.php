<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_stream_events (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE CASCADE,
                  ai_job_id UUID REFERENCES ai_jobs(id) ON DELETE CASCADE,
                  ai_job_attempt_id UUID REFERENCES ai_job_attempts(id) ON DELETE SET NULL,
                  sequence INTEGER NOT NULL CHECK (sequence > 0),
                  event_type TEXT NOT NULL CHECK (event_type IN (
                    'lifecycle',
                    'permission',
                    'progress',
                    'stdout',
                    'stderr',
                    'token',
                    'response',
                    'error'
                  )),
                  channel TEXT,
                  content TEXT NOT NULL DEFAULT '',
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  UNIQUE(ai_job_id, sequence)
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_stream_events_trace_sequence ON ai_stream_events(trace_id, sequence);');
            DB::statement('CREATE INDEX idx_ai_stream_events_job_sequence ON ai_stream_events(ai_job_id, sequence);');
            DB::statement('CREATE INDEX idx_ai_stream_events_type ON ai_stream_events(event_type, occurred_at DESC);');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_stream_events;');
        });
    }
};
