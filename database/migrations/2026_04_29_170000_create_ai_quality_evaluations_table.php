<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_quality_evaluations (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  provider TEXT,
                  model TEXT,
                  agent_slug TEXT,
                  evaluator_version TEXT NOT NULL DEFAULT 'heuristic-v1',
                  score SMALLINT NOT NULL CHECK (score BETWEEN 0 AND 100),
                  status TEXT NOT NULL CHECK (status IN ('passed', 'needs_review', 'failed')),
                  dimensions JSONB NOT NULL DEFAULT '{}'::jsonb,
                  flags JSONB NOT NULL DEFAULT '[]'::jsonb,
                  suggested_actions JSONB NOT NULL DEFAULT '[]'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE UNIQUE INDEX idx_ai_quality_evaluations_trace_unique ON ai_quality_evaluations(trace_id) WHERE trace_id IS NOT NULL;');
            DB::statement('CREATE INDEX idx_ai_quality_evaluations_thread_created ON ai_quality_evaluations(thread_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_quality_evaluations_session_created ON ai_quality_evaluations(session_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_quality_evaluations_status_score ON ai_quality_evaluations(status, score);');
            DB::statement('CREATE INDEX idx_ai_quality_evaluations_flags ON ai_quality_evaluations USING GIN(flags);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_quality_evaluations_updated_at
                BEFORE UPDATE ON ai_quality_evaluations
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_quality_evaluations;');
        });
    }
};
