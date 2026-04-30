<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_threads (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  title TEXT NOT NULL,
                  summary TEXT,
                  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN (
                    'active',
                    'archived',
                    'closed'
                  )),
                  surface TEXT NOT NULL DEFAULT 'app',
                  workspace TEXT,
                  source_type TEXT,
                  source_id UUID,
                  last_trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  last_provider TEXT,
                  message_count INTEGER NOT NULL DEFAULT 0 CHECK (message_count >= 0),
                  last_message_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_threads_status_last_message ON ai_threads(status, last_message_at DESC NULLS LAST, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_threads_surface ON ai_threads(surface, workspace, last_message_at DESC NULLS LAST);');
            DB::statement('CREATE INDEX idx_ai_threads_source ON ai_threads(source_type, source_id);');
            DB::statement('CREATE INDEX idx_ai_threads_metadata ON ai_threads USING GIN(metadata);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_threads_updated_at
                BEFORE UPDATE ON ai_threads
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE ai_traces
                ADD COLUMN thread_id UUID;
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE ai_traces
                ADD CONSTRAINT ai_traces_thread_id_foreign
                FOREIGN KEY (thread_id) REFERENCES ai_threads(id) ON DELETE SET NULL;
            SQL);
            DB::statement('CREATE INDEX idx_ai_traces_thread ON ai_traces(thread_id, created_at DESC);');

            DB::statement(<<<'SQL'
                CREATE TABLE ai_messages (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  position INTEGER NOT NULL CHECK (position > 0),
                  role TEXT NOT NULL CHECK (role IN (
                    'user',
                    'assistant',
                    'system',
                    'tool',
                    'summary'
                  )),
                  status TEXT NOT NULL DEFAULT 'final' CHECK (status IN (
                    'draft',
                    'final',
                    'failed',
                    'redacted'
                  )),
                  content TEXT NOT NULL,
                  provider TEXT,
                  model TEXT,
                  agent_slug TEXT,
                  token_estimate INTEGER CHECK (token_estimate IS NULL OR token_estimate >= 0),
                  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  UNIQUE(thread_id, position)
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_messages_thread_position ON ai_messages(thread_id, position);');
            DB::statement('CREATE INDEX idx_ai_messages_thread_recent ON ai_messages(thread_id, occurred_at DESC);');
            DB::statement('CREATE INDEX idx_ai_messages_trace ON ai_messages(trace_id);');
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX idx_ai_messages_trace_role_unique
                ON ai_messages(trace_id, role)
                WHERE trace_id IS NOT NULL AND role IN ('user', 'assistant');
            SQL);
            DB::statement('CREATE INDEX idx_ai_messages_metadata ON ai_messages USING GIN(metadata);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_messages_updated_at
                BEFORE UPDATE ON ai_messages
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_messages;');
            DB::statement('DROP INDEX IF EXISTS idx_ai_traces_thread;');
            DB::statement('ALTER TABLE ai_traces DROP CONSTRAINT IF EXISTS ai_traces_thread_id_foreign;');
            DB::statement('ALTER TABLE ai_traces DROP COLUMN IF EXISTS thread_id;');
            DB::statement('DROP TABLE IF EXISTS ai_threads;');
        });
    }
};
