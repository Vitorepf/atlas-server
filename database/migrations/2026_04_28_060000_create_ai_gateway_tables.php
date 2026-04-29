<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE ai_traces (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  trace_key TEXT NOT NULL UNIQUE,
                  source_type TEXT NOT NULL DEFAULT 'manual' CHECK (source_type IN (
                    'manual',
                    'app',
                    'capture',
                    'semantic_memory',
                    'scheduled',
                    'system'
                  )),
                  source_id UUID,
                  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN (
                    'queued',
                    'processing',
                    'succeeded',
                    'failed',
                    'cancelled'
                  )),
                  operator_input TEXT NOT NULL,
                  intent TEXT,
                  agent_slug TEXT NOT NULL,
                  provider TEXT,
                  model TEXT,
                  skill_versions JSONB NOT NULL DEFAULT '{}'::jsonb,
                  context_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
                  prompt_hash TEXT,
                  response_hash TEXT,
                  response_text TEXT,
                  latency_ms INTEGER CHECK (latency_ms IS NULL OR latency_ms >= 0),
                  feedback_score SMALLINT CHECK (feedback_score IS NULL OR feedback_score BETWEEN 1 AND 5),
                  feedback_action TEXT CHECK (feedback_action IS NULL OR feedback_action IN (
                    'useful',
                    'not_useful',
                    'wrong_agent',
                    'wrong_context',
                    'too_slow',
                    'too_expensive',
                    'unsafe',
                    'dismissed'
                  )),
                  feedback_comment TEXT,
                  completed_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_traces_status ON ai_traces(status, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_traces_agent ON ai_traces(agent_slug, created_at DESC);');
            DB::statement('CREATE INDEX idx_ai_traces_context_refs ON ai_traces USING GIN(context_refs);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_traces_updated_at
                BEFORE UPDATE ON ai_traces
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE ai_jobs (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
                  client_id UUID UNIQUE,
                  kind TEXT NOT NULL DEFAULT 'interaction' CHECK (kind IN (
                    'interaction',
                    'curation',
                    'analysis',
                    'council',
                    'skill_test',
                    'manual'
                  )),
                  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN (
                    'queued',
                    'processing',
                    'succeeded',
                    'failed',
                    'cancelled'
                  )),
                  priority SMALLINT NOT NULL DEFAULT 50 CHECK (priority BETWEEN 0 AND 100),
                  agent_slug TEXT NOT NULL,
                  provider TEXT,
                  model TEXT,
                  input_text TEXT NOT NULL,
                  prompt TEXT NOT NULL,
                  context_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
                  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  result_text TEXT,
                  result_json JSONB,
                  error_code TEXT,
                  error_message TEXT,
                  available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  reserved_at TIMESTAMPTZ,
                  started_at TIMESTAMPTZ,
                  finished_at TIMESTAMPTZ,
                  attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                  max_attempts INTEGER NOT NULL DEFAULT 2 CHECK (max_attempts > 0),
                  timeout_seconds INTEGER NOT NULL DEFAULT 300 CHECK (timeout_seconds > 0),
                  worker_id TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_jobs_queue ON ai_jobs(status, priority, available_at, created_at);');
            DB::statement('CREATE INDEX idx_ai_jobs_trace ON ai_jobs(trace_id);');
            DB::statement('CREATE INDEX idx_ai_jobs_worker ON ai_jobs(worker_id, started_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_jobs_updated_at
                BEFORE UPDATE ON ai_jobs
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE ai_job_attempts (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  ai_job_id UUID NOT NULL REFERENCES ai_jobs(id) ON DELETE CASCADE,
                  attempt_number INTEGER NOT NULL CHECK (attempt_number > 0),
                  worker_id TEXT NOT NULL,
                  provider TEXT NOT NULL,
                  model TEXT,
                  command JSONB NOT NULL DEFAULT '[]'::jsonb,
                  command_hash TEXT,
                  prompt_hash TEXT NOT NULL,
                  response_hash TEXT,
                  status TEXT NOT NULL DEFAULT 'processing' CHECK (status IN (
                    'processing',
                    'succeeded',
                    'failed',
                    'timeout',
                    'cancelled'
                  )),
                  exit_code INTEGER,
                  duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
                  output_text TEXT,
                  stdout_excerpt TEXT,
                  stderr_excerpt TEXT,
                  error_code TEXT,
                  error_message TEXT,
                  started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  finished_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  UNIQUE(ai_job_id, attempt_number)
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_job_attempts_job ON ai_job_attempts(ai_job_id, attempt_number DESC);');
            DB::statement('CREATE INDEX idx_ai_job_attempts_provider ON ai_job_attempts(provider, started_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_job_attempts_updated_at
                BEFORE UPDATE ON ai_job_attempts
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE ai_worker_events (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  worker_id TEXT NOT NULL,
                  provider TEXT,
                  ai_job_id UUID REFERENCES ai_jobs(id) ON DELETE SET NULL,
                  ai_job_attempt_id UUID REFERENCES ai_job_attempts(id) ON DELETE SET NULL,
                  event_type TEXT NOT NULL CHECK (event_type IN (
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
                  )),
                  severity TEXT NOT NULL DEFAULT 'info' CHECK (severity IN (
                    'debug',
                    'info',
                    'warning',
                    'error',
                    'critical'
                  )),
                  message TEXT NOT NULL,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_worker_events_worker ON ai_worker_events(worker_id, occurred_at DESC);');
            DB::statement('CREATE INDEX idx_ai_worker_events_type ON ai_worker_events(event_type, occurred_at DESC);');
            DB::statement('CREATE INDEX idx_ai_worker_events_job ON ai_worker_events(ai_job_id);');

            DB::statement(<<<'SQL'
                CREATE TABLE ai_provider_health_snapshots (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  provider TEXT NOT NULL,
                  status TEXT NOT NULL CHECK (status IN (
                    'online',
                    'degraded',
                    'offline',
                    'unknown'
                  )),
                  checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  last_success_at TIMESTAMPTZ,
                  last_failure_at TIMESTAMPTZ,
                  total_jobs_24h INTEGER NOT NULL DEFAULT 0 CHECK (total_jobs_24h >= 0),
                  failed_jobs_24h INTEGER NOT NULL DEFAULT 0 CHECK (failed_jobs_24h >= 0),
                  p50_latency_ms INTEGER CHECK (p50_latency_ms IS NULL OR p50_latency_ms >= 0),
                  operational_pain_score SMALLINT NOT NULL DEFAULT 0 CHECK (operational_pain_score BETWEEN 0 AND 5),
                  message TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_ai_provider_health_provider ON ai_provider_health_snapshots(provider, checked_at DESC);');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS ai_provider_health_snapshots;');
            DB::statement('DROP TABLE IF EXISTS ai_worker_events;');
            DB::statement('DROP TABLE IF EXISTS ai_job_attempts;');
            DB::statement('DROP TABLE IF EXISTS ai_jobs;');
            DB::statement('DROP TABLE IF EXISTS ai_traces;');
        });
    }
};
