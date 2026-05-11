<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_quality_actions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  evaluation_id UUID REFERENCES ai_quality_evaluations(id) ON DELETE SET NULL,
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  remediation_trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  action_type TEXT NOT NULL CHECK (action_type IN (
                    'retry_with_continuity',
                    'rewrite_for_operator',
                    'escalate_to_council',
                    'fair_claude_repair',
                    'request_verification',
                    'enforce_atlas_identity',
                    'operator_review'
                  )),
                  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN (
                    'queued',
                    'running',
                    'succeeded',
                    'failed',
                    'skipped',
                    'blocked'
                  )),
                  priority SMALLINT NOT NULL DEFAULT 50 CHECK (priority BETWEEN 0 AND 100),
                  reason TEXT NOT NULL,
                  flags JSONB NOT NULL DEFAULT '[]'::jsonb,
                  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  result JSONB NOT NULL DEFAULT '{}'::jsonb,
                  error_message TEXT,
                  dedupe_key TEXT NOT NULL UNIQUE,
                  completed_at TIMESTAMPTZ,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_quality_actions_trace_created ON ai_quality_actions(trace_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_quality_actions_thread_status ON ai_quality_actions(thread_id, status, priority);');
            DB::statement('CREATE INDEX idx_ai_quality_actions_status_priority ON ai_quality_actions(status, priority, created_at);');
            DB::statement('CREATE INDEX idx_ai_quality_actions_remediation_trace ON ai_quality_actions(remediation_trace_id);');
            DB::statement('CREATE INDEX idx_ai_quality_actions_payload ON ai_quality_actions USING GIN(payload);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_quality_actions_updated_at
                BEFORE UPDATE ON ai_quality_actions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_quality_actions;');
        });
    }
};
