<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_sessions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
                  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN (
                    'active',
                    'paused',
                    'completed',
                    'abandoned'
                  )),
                  purpose TEXT,
                  provider_primary TEXT,
                  provider_last TEXT,
                  started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  ended_at TIMESTAMPTZ,
                  message_count INTEGER NOT NULL DEFAULT 0 CHECK (message_count >= 0),
                  token_estimate INTEGER NOT NULL DEFAULT 0 CHECK (token_estimate >= 0),
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_sessions_thread_status ON ai_sessions(thread_id, status, started_at DESC);');
            DB::statement('CREATE UNIQUE INDEX idx_ai_sessions_one_active_per_thread ON ai_sessions(thread_id) WHERE status = \'active\';');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_sessions_updated_at
                BEFORE UPDATE ON ai_sessions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement('ALTER TABLE ai_traces ADD COLUMN session_id UUID;');
            DB::statement(<<<'SQL'
                ALTER TABLE ai_traces
                ADD CONSTRAINT ai_traces_session_id_foreign
                FOREIGN KEY (session_id) REFERENCES ai_sessions(id) ON DELETE SET NULL;
            SQL);
            DB::statement('CREATE INDEX idx_ai_traces_session ON ai_traces(session_id, created_at DESC);');

            DB::statement(<<<'SQL'
                CREATE TABLE ai_session_states (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
                  active BOOLEAN NOT NULL DEFAULT TRUE,
                  objective TEXT,
                  current_phase TEXT,
                  current_topic TEXT,
                  user_position TEXT,
                  decisions JSONB NOT NULL DEFAULT '[]'::jsonb,
                  open_loops JSONB NOT NULL DEFAULT '[]'::jsonb,
                  next_steps JSONB NOT NULL DEFAULT '[]'::jsonb,
                  relevant_artifacts JSONB NOT NULL DEFAULT '[]'::jsonb,
                  constraints JSONB NOT NULL DEFAULT '[]'::jsonb,
                  provider_context JSONB NOT NULL DEFAULT '{}'::jsonb,
                  quality_notes JSONB NOT NULL DEFAULT '[]'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_session_states_thread_active ON ai_session_states(thread_id, active, updated_at DESC);');
            DB::statement('CREATE INDEX idx_ai_session_states_session ON ai_session_states(session_id, updated_at DESC);');
            DB::statement('CREATE UNIQUE INDEX idx_ai_session_states_one_active_per_thread ON ai_session_states(thread_id) WHERE active = TRUE;');
            DB::statement('CREATE INDEX idx_ai_session_states_metadata ON ai_session_states USING GIN(metadata);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_session_states_updated_at
                BEFORE UPDATE ON ai_session_states
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE ai_compactions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  reason TEXT NOT NULL CHECK (reason IN (
                    'manual',
                    'auto',
                    'provider_switch',
                    'phase_change',
                    'session_resume',
                    'session_close'
                  )),
                  source_position_start INTEGER,
                  source_position_end INTEGER,
                  source_message_count INTEGER NOT NULL DEFAULT 0 CHECK (source_message_count >= 0),
                  summary TEXT NOT NULL,
                  structured_state JSONB NOT NULL DEFAULT '{}'::jsonb,
                  token_estimate_before INTEGER CHECK (token_estimate_before IS NULL OR token_estimate_before >= 0),
                  token_estimate_after INTEGER CHECK (token_estimate_after IS NULL OR token_estimate_after >= 0),
                  quality_gate_status TEXT NOT NULL DEFAULT 'passed' CHECK (quality_gate_status IN (
                    'passed',
                    'failed',
                    'needs_review'
                  )),
                  provider TEXT,
                  model TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_compactions_thread_created ON ai_compactions(thread_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_compactions_session_created ON ai_compactions(session_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_compactions_structured_state ON ai_compactions USING GIN(structured_state);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_compactions_updated_at
                BEFORE UPDATE ON ai_compactions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE ai_context_snapshots (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  provider TEXT,
                  model TEXT,
                  prompt_hash TEXT,
                  context_pack JSONB NOT NULL DEFAULT '{}'::jsonb,
                  messages_included JSONB NOT NULL DEFAULT '[]'::jsonb,
                  compaction_id UUID REFERENCES ai_compactions(id) ON DELETE SET NULL,
                  provider_handoff_id UUID,
                  token_estimate INTEGER CHECK (token_estimate IS NULL OR token_estimate >= 0),
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_context_snapshots_trace ON ai_context_snapshots(trace_id);');
            DB::statement('CREATE INDEX idx_ai_context_snapshots_thread_created ON ai_context_snapshots(thread_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_context_snapshots_context_pack ON ai_context_snapshots USING GIN(context_pack);');

            DB::statement(<<<'SQL'
                CREATE TABLE ai_provider_handoffs (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
                  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
                  from_provider TEXT,
                  to_provider TEXT NOT NULL,
                  reason TEXT NOT NULL DEFAULT 'provider_switch',
                  brief_text TEXT NOT NULL,
                  brief_json JSONB NOT NULL DEFAULT '{}'::jsonb,
                  compaction_id UUID REFERENCES ai_compactions(id) ON DELETE SET NULL,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('ALTER TABLE ai_context_snapshots ADD CONSTRAINT ai_context_snapshots_provider_handoff_foreign FOREIGN KEY (provider_handoff_id) REFERENCES ai_provider_handoffs(id) ON DELETE SET NULL;');
            DB::statement('CREATE INDEX idx_ai_provider_handoffs_thread_created ON ai_provider_handoffs(thread_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_provider_handoffs_session_created ON ai_provider_handoffs(session_id, created_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_provider_handoffs_updated_at
                BEFORE UPDATE ON ai_provider_handoffs
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_context_snapshots;');
            DB::statement('DROP TABLE IF EXISTS ai_provider_handoffs;');
            DB::statement('DROP TABLE IF EXISTS ai_compactions;');
            DB::statement('DROP TABLE IF EXISTS ai_session_states;');
            DB::statement('ALTER TABLE ai_traces DROP CONSTRAINT IF EXISTS ai_traces_session_id_foreign;');
            DB::statement('DROP INDEX IF EXISTS idx_ai_traces_session;');
            DB::statement('ALTER TABLE ai_traces DROP COLUMN IF EXISTS session_id;');
            DB::statement('DROP TABLE IF EXISTS ai_sessions;');
        });
    }
};
