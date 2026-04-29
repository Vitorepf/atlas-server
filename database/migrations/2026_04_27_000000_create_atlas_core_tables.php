<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS vector;

            CREATE OR REPLACE FUNCTION set_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
              NEW.updated_at = NOW();
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TABLE captures (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              client_id UUID NOT NULL UNIQUE,

              kind TEXT NOT NULL CHECK (kind IN ('audio', 'text', 'photo')),
              domain TEXT NOT NULL CHECK (domain IN ('blackink', 'saude', 'financas', 'outro')),

              content_text TEXT,
              content_file_path TEXT,
              content_duration_ms INTEGER CHECK (content_duration_ms IS NULL OR content_duration_ms >= 0),
              content_size_bytes INTEGER CHECK (content_size_bytes IS NULL OR content_size_bytes >= 0),
              content_sha256 TEXT,
              content_mime_type TEXT,

              transcription_status TEXT NOT NULL DEFAULT 'pending'
                CHECK (transcription_status IN ('pending', 'processing', 'done', 'failed', 'na')),
              transcription_engine TEXT,
              transcription_error TEXT,

              captured_at TIMESTAMPTZ NOT NULL,
              captured_timezone TEXT NOT NULL,
              captured_lat NUMERIC(10, 7),
              captured_lng NUMERIC(10, 7),

              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ
            );

            CREATE INDEX idx_captures_captured_at ON captures(captured_at DESC) WHERE deleted_at IS NULL;
            CREATE INDEX idx_captures_domain ON captures(domain) WHERE deleted_at IS NULL;
            CREATE INDEX idx_captures_kind ON captures(kind) WHERE deleted_at IS NULL;
            CREATE INDEX idx_captures_updated_at ON captures(updated_at DESC);
            CREATE INDEX idx_captures_client_id ON captures(client_id);

            CREATE TRIGGER trg_captures_updated_at
            BEFORE UPDATE ON captures
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE checkins (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              client_id UUID NOT NULL UNIQUE,

              state TEXT NOT NULL CHECK (state IN ('focused', 'disperse', 'blocked', 'pause')),
              note TEXT,

              recorded_at TIMESTAMPTZ NOT NULL,
              recorded_timezone TEXT NOT NULL,

              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ
            );

            CREATE INDEX idx_checkins_recorded_at ON checkins(recorded_at DESC) WHERE deleted_at IS NULL;
            CREATE INDEX idx_checkins_state ON checkins(state) WHERE deleted_at IS NULL;
            CREATE INDEX idx_checkins_updated_at ON checkins(updated_at DESC);
            CREATE INDEX idx_checkins_client_id ON checkins(client_id);

            CREATE TRIGGER trg_checkins_updated_at
            BEFORE UPDATE ON checkins
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE transcription_jobs (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              capture_id UUID NOT NULL REFERENCES captures(id) ON DELETE CASCADE,

              status TEXT NOT NULL DEFAULT 'queued'
                CHECK (status IN ('queued', 'processing', 'done', 'failed')),

              attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
              max_attempts INTEGER NOT NULL DEFAULT 3 CHECK (max_attempts > 0),

              started_at TIMESTAMPTZ,
              finished_at TIMESTAMPTZ,
              error_message TEXT,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_transcription_jobs_status ON transcription_jobs(status, created_at);
            CREATE UNIQUE INDEX idx_transcription_jobs_active_capture
              ON transcription_jobs(capture_id)
              WHERE status IN ('queued', 'processing');

            CREATE TRIGGER trg_transcription_jobs_updated_at
            BEFORE UPDATE ON transcription_jobs
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE sync_log (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              device_id TEXT NOT NULL,

              synced_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              captures_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (captures_uploaded >= 0),
              captures_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (captures_downloaded >= 0),
              checkins_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (checkins_uploaded >= 0),
              checkins_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (checkins_downloaded >= 0),

              duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb
            );

            CREATE INDEX idx_sync_log_device_synced ON sync_log(device_id, synced_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS sync_log;
            DROP TABLE IF EXISTS transcription_jobs;
            DROP TABLE IF EXISTS checkins;
            DROP TABLE IF EXISTS captures;
            DROP FUNCTION IF EXISTS set_updated_at();
        SQL);
    }
};
