<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE digital_category_mappings (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  source_identifier TEXT NOT NULL,
                  source_name TEXT NOT NULL,
                  source_kind TEXT NOT NULL DEFAULT 'app'
                    CHECK (source_kind IN ('app', 'domain', 'url', 'project', 'category', 'unknown')),
                  category_class INTEGER NOT NULL CHECK (category_class BETWEEN 1 AND 10),
                  category_label TEXT NOT NULL,
                  intentionality TEXT NOT NULL DEFAULT 'unknown'
                    CHECK (intentionality IN ('intentional', 'default', 'mixed', 'unknown')),
                  classified_by TEXT NOT NULL DEFAULT 'operator'
                    CHECK (classified_by IN ('operator', 'system_suggestion', 'import')),
                  confidence NUMERIC(4, 3) CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
                  valid_from TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  valid_until TIMESTAMPTZ,
                  notes TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  CONSTRAINT digital_category_mappings_valid_range
                    CHECK (valid_until IS NULL OR valid_until > valid_from)
                );
            SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX digital_category_mappings_current_unique
                  ON digital_category_mappings(source_identifier, source_kind)
                  WHERE valid_until IS NULL;
            SQL);

            DB::statement('CREATE INDEX idx_digital_category_mappings_class ON digital_category_mappings(category_class) WHERE valid_until IS NULL;');
            DB::statement('CREATE INDEX idx_digital_category_mappings_identifier ON digital_category_mappings(source_identifier, valid_from DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_digital_category_mappings_updated_at
                BEFORE UPDATE ON digital_category_mappings
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE digital_sessions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  client_id UUID NOT NULL UNIQUE,
                  source TEXT NOT NULL DEFAULT 'rize'
                    CHECK (source IN ('rize', 'screentime', 'manual', 'import')),
                  source_event_id TEXT,
                  source_identifier TEXT NOT NULL,
                  source_name TEXT NOT NULL,
                  source_kind TEXT NOT NULL DEFAULT 'app'
                    CHECK (source_kind IN ('app', 'domain', 'url', 'project', 'category', 'unknown')),
                  category_class_at_time INTEGER CHECK (category_class_at_time IS NULL OR category_class_at_time BETWEEN 1 AND 10),
                  category_label_at_time TEXT,
                  intentionality TEXT NOT NULL DEFAULT 'unknown'
                    CHECK (intentionality IN ('intentional', 'default', 'mixed', 'unknown')),
                  started_at TIMESTAMPTZ NOT NULL,
                  ended_at TIMESTAMPTZ NOT NULL,
                  duration_seconds INTEGER NOT NULL CHECK (duration_seconds >= 0),
                  recorded_timezone TEXT NOT NULL,
                  focus_mode_active TEXT,
                  project_name TEXT,
                  task_name TEXT,
                  url_domain TEXT,
                  productivity_score NUMERIC(6, 3),
                  linked_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
                  linked_decision_id UUID,
                  raw_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ,
                  CONSTRAINT digital_sessions_ended_after_started
                    CHECK (ended_at >= started_at)
                );
            SQL);

            DB::statement('CREATE INDEX idx_digital_sessions_started_at ON digital_sessions(started_at DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_digital_sessions_source_identifier ON digital_sessions(source, source_identifier, started_at DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_digital_sessions_category ON digital_sessions(category_class_at_time, started_at DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_digital_sessions_updated_at ON digital_sessions(updated_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_digital_sessions_updated_at
                BEFORE UPDATE ON digital_sessions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE digital_activity_snapshots (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  client_id UUID NOT NULL UNIQUE,
                  source TEXT NOT NULL DEFAULT 'atlas_server'
                    CHECK (source IN ('atlas_server', 'rize', 'screentime', 'manual', 'import')),
                  snapshot_date DATE NOT NULL,
                  snapshot_timezone TEXT NOT NULL,
                  computed_at TIMESTAMPTZ NOT NULL,
                  signal_count INTEGER NOT NULL DEFAULT 0 CHECK (signal_count >= 0),
                  total_screen_time_min INTEGER,
                  pickups_count INTEGER,
                  first_offensive_use_min_after_wake INTEGER,
                  deep_work_sessions_count INTEGER,
                  deep_work_total_min INTEGER,
                  notifications_received INTEGER,
                  notifications_actioned INTEGER,
                  curated_input_min INTEGER,
                  algorithmic_input_min INTEGER,
                  intentional_entertainment_min INTEGER,
                  default_entertainment_min INTEGER,
                  communication_primary_min INTEGER,
                  communication_shallow_min INTEGER,
                  market_min INTEGER,
                  focus_mode_active_min JSONB NOT NULL DEFAULT '{}'::jsonb,
                  category_breakdown JSONB NOT NULL DEFAULT '{}'::jsonb,
                  source_breakdown JSONB NOT NULL DEFAULT '{}'::jsonb,
                  raw_rize_data JSONB NOT NULL DEFAULT '{}'::jsonb,
                  raw_screentime_data JSONB NOT NULL DEFAULT '{}'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ,
                  CONSTRAINT digital_activity_snapshots_source_date_timezone_unique
                    UNIQUE (source, snapshot_date, snapshot_timezone)
                );
            SQL);

            DB::statement('CREATE INDEX idx_digital_activity_snapshots_date ON digital_activity_snapshots(snapshot_date DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_digital_activity_snapshots_updated_at ON digital_activity_snapshots(updated_at DESC);');
            DB::statement('CREATE INDEX idx_digital_activity_snapshots_algorithmic ON digital_activity_snapshots(algorithmic_input_min DESC NULLS LAST, snapshot_date DESC) WHERE deleted_at IS NULL;');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_digital_activity_snapshots_updated_at
                BEFORE UPDATE ON digital_activity_snapshots
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE procrastination_events (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  client_id UUID NOT NULL UNIQUE,
                  detected_at TIMESTAMPTZ NOT NULL,
                  detected_timezone TEXT NOT NULL,
                  duration_min INTEGER NOT NULL CHECK (duration_min >= 0),
                  primary_category_class INTEGER CHECK (primary_category_class IS NULL OR primary_category_class BETWEEN 1 AND 10),
                  primary_category_label TEXT,
                  mission_active BOOLEAN NOT NULL DEFAULT FALSE,
                  mission_context JSONB NOT NULL DEFAULT '{}'::jsonb,
                  physiological_state JSONB NOT NULL DEFAULT '{}'::jsonb,
                  subjective_state JSONB NOT NULL DEFAULT '{}'::jsonb,
                  digital_context JSONB NOT NULL DEFAULT '{}'::jsonb,
                  rule_version TEXT NOT NULL DEFAULT 'sensor4-v1',
                  confidence NUMERIC(4, 3) CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
                  confronted BOOLEAN NOT NULL DEFAULT FALSE,
                  operator_response TEXT CHECK (operator_response IS NULL OR operator_response IN ('accepted', 'dismissed', 'snoozed', 'false_positive')),
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ
                );
            SQL);

            DB::statement('CREATE INDEX idx_procrastination_events_detected_at ON procrastination_events(detected_at DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_procrastination_events_updated_at ON procrastination_events(updated_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_procrastination_events_updated_at
                BEFORE UPDATE ON procrastination_events
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE digital_import_events (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  source TEXT NOT NULL DEFAULT 'rize'
                    CHECK (source IN ('rize', 'screentime', 'manual', 'import')),
                  source_event_id TEXT,
                  event_type TEXT NOT NULL,
                  received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  processed_at TIMESTAMPTZ,
                  status TEXT NOT NULL DEFAULT 'received'
                    CHECK (status IN ('received', 'processed', 'ignored', 'failed')),
                  error_message TEXT,
                  raw_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_digital_import_events_received_at ON digital_import_events(received_at DESC);');
            DB::statement('CREATE INDEX idx_digital_import_events_source_event ON digital_import_events(source, source_event_id);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_digital_import_events_updated_at
                BEFORE UPDATE ON digital_import_events
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE captures
                  ADD COLUMN pre_capture_digital_context JSONB NOT NULL DEFAULT '{}'::jsonb;
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE sync_log
                  ADD COLUMN digital_sessions_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (digital_sessions_uploaded >= 0),
                  ADD COLUMN digital_sessions_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (digital_sessions_downloaded >= 0),
                  ADD COLUMN digital_snapshots_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (digital_snapshots_uploaded >= 0),
                  ADD COLUMN digital_snapshots_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (digital_snapshots_downloaded >= 0);
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE sync_log
                  DROP COLUMN IF EXISTS digital_snapshots_downloaded,
                  DROP COLUMN IF EXISTS digital_snapshots_uploaded,
                  DROP COLUMN IF EXISTS digital_sessions_downloaded,
                  DROP COLUMN IF EXISTS digital_sessions_uploaded;
            SQL);
            DB::statement('ALTER TABLE captures DROP COLUMN IF EXISTS pre_capture_digital_context;');
            DB::statement('DROP TABLE IF EXISTS digital_import_events;');
            DB::statement('DROP TABLE IF EXISTS procrastination_events;');
            DB::statement('DROP TABLE IF EXISTS digital_activity_snapshots;');
            DB::statement('DROP TABLE IF EXISTS digital_sessions;');
            DB::statement('DROP TABLE IF EXISTS digital_category_mappings;');
        });
    }
};
