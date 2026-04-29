<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE behaviors (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  client_id UUID NOT NULL UNIQUE,
                  name TEXT NOT NULL,
                  slug TEXT NOT NULL,
                  category TEXT NOT NULL DEFAULT 'outro'
                    CHECK (category IN ('bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'outro')),
                  input_type TEXT NOT NULL DEFAULT 'yes_no'
                    CHECK (input_type IN ('yes_no', 'scale_1_5', 'count_int', 'text_short')),
                  question_text TEXT NOT NULL,
                  default_value TEXT NOT NULL DEFAULT 'no',
                  created_by TEXT NOT NULL DEFAULT 'operator'
                    CHECK (created_by IN ('operator', 'ai_suggestion', 'import')),
                  source_capture_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
                  activation_rules JSONB NOT NULL DEFAULT '{}'::jsonb,
                  show_in_morning_briefing BOOLEAN NOT NULL DEFAULT TRUE,
                  priority_score INTEGER NOT NULL DEFAULT 0,
                  streak_yes INTEGER NOT NULL DEFAULT 0 CHECK (streak_yes >= 0),
                  streak_no INTEGER NOT NULL DEFAULT 0 CHECK (streak_no >= 0),
                  total_yes_count INTEGER NOT NULL DEFAULT 0 CHECK (total_yes_count >= 0),
                  total_no_count INTEGER NOT NULL DEFAULT 0 CHECK (total_no_count >= 0),
                  relational_privacy BOOLEAN NOT NULL DEFAULT FALSE,
                  activated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  archived_at TIMESTAMPTZ,
                  promoted_to_object_type TEXT,
                  promoted_to_object_id UUID,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ,
                  CONSTRAINT behaviors_name_not_blank CHECK (length(trim(name)) > 0),
                  CONSTRAINT behaviors_slug_not_blank CHECK (length(trim(slug)) > 0)
                );
            SQL);

            DB::statement('CREATE UNIQUE INDEX behaviors_slug_active_unique ON behaviors(slug) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_behaviors_briefing ON behaviors(show_in_morning_briefing, priority_score DESC, activated_at DESC) WHERE deleted_at IS NULL AND archived_at IS NULL;');
            DB::statement('CREATE INDEX idx_behaviors_updated_at ON behaviors(updated_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_behaviors_updated_at
                BEFORE UPDATE ON behaviors
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE behavior_logs (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  client_id UUID NOT NULL UNIQUE,
                  behavior_id UUID REFERENCES behaviors(id) ON DELETE CASCADE,
                  behavior_client_id UUID NOT NULL,
                  log_date DATE NOT NULL,
                  value TEXT NOT NULL,
                  numeric_value NUMERIC(10, 3),
                  note TEXT,
                  recorded_at TIMESTAMPTZ NOT NULL,
                  recorded_timezone TEXT NOT NULL,
                  source TEXT NOT NULL DEFAULT 'manual'
                    CHECK (source IN ('morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import')),
                  source_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
                  auto_marked BOOLEAN NOT NULL DEFAULT FALSE,
                  confirmed_by_operator BOOLEAN NOT NULL DEFAULT TRUE,
                  reverted_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ,
                  CONSTRAINT behavior_logs_value_not_blank CHECK (length(trim(value)) > 0)
                );
            SQL);

            DB::statement('CREATE UNIQUE INDEX behavior_logs_behavior_day_unique ON behavior_logs(behavior_client_id, log_date) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_behavior_logs_date ON behavior_logs(log_date DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_behavior_logs_behavior_date ON behavior_logs(behavior_client_id, log_date DESC) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_behavior_logs_updated_at ON behavior_logs(updated_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_behavior_logs_updated_at
                BEFORE UPDATE ON behavior_logs
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE behavior_correlations (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  behavior_id UUID NOT NULL REFERENCES behaviors(id) ON DELETE CASCADE,
                  outcome_type TEXT NOT NULL
                    CHECK (outcome_type IN ('hrv', 'sleep', 'energy', 'mood', 'complexity', 'application_ratio', 'digital_focus', 'other')),
                  outcome_source TEXT NOT NULL,
                  window_days INTEGER NOT NULL CHECK (window_days > 0),
                  yes_count INTEGER NOT NULL DEFAULT 0 CHECK (yes_count >= 0),
                  no_count INTEGER NOT NULL DEFAULT 0 CHECK (no_count >= 0),
                  outcome_avg_yes NUMERIC(12, 4),
                  outcome_avg_no NUMERIC(12, 4),
                  difference_pct NUMERIC(12, 4),
                  confidence_level TEXT NOT NULL DEFAULT 'low'
                    CHECK (confidence_level IN ('low', 'medium', 'high')),
                  confounders_detected JSONB NOT NULL DEFAULT '[]'::jsonb,
                  sample_sufficient BOOLEAN NOT NULL DEFAULT FALSE,
                  computed_at TIMESTAMPTZ NOT NULL,
                  computed_by_provider TEXT,
                  computed_by_version TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_behavior_correlations_behavior ON behavior_correlations(behavior_id, computed_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_behavior_correlations_updated_at
                BEFORE UPDATE ON behavior_correlations
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE behavior_suggestions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  suggested_name TEXT NOT NULL,
                  source_capture_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
                  detection_score NUMERIC(4, 3) CHECK (detection_score IS NULL OR detection_score BETWEEN 0 AND 1),
                  status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'accepted', 'postponed', 'ignored_permanently')),
                  shown_count INTEGER NOT NULL DEFAULT 0 CHECK (shown_count >= 0),
                  last_shown_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  resolved_at TIMESTAMPTZ
                );
            SQL);

            DB::statement('CREATE INDEX idx_behavior_suggestions_status ON behavior_suggestions(status, created_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_behavior_suggestions_updated_at
                BEFORE UPDATE ON behavior_suggestions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE behavior_promotions (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  behavior_id UUID NOT NULL REFERENCES behaviors(id) ON DELETE CASCADE,
                  target_object_type TEXT NOT NULL,
                  target_object_id UUID NOT NULL,
                  promoted_at TIMESTAMPTZ NOT NULL,
                  days_tracked_at_promotion INTEGER NOT NULL CHECK (days_tracked_at_promotion >= 0),
                  adherence_at_promotion NUMERIC(5, 2),
                  notes TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_behavior_promotions_behavior ON behavior_promotions(behavior_id, promoted_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_behavior_promotions_updated_at
                BEFORE UPDATE ON behavior_promotions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE sync_log
                  ADD COLUMN behaviors_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (behaviors_uploaded >= 0),
                  ADD COLUMN behaviors_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (behaviors_downloaded >= 0),
                  ADD COLUMN behavior_logs_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (behavior_logs_uploaded >= 0),
                  ADD COLUMN behavior_logs_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (behavior_logs_downloaded >= 0);
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE sync_log
                  DROP COLUMN IF EXISTS behavior_logs_downloaded,
                  DROP COLUMN IF EXISTS behavior_logs_uploaded,
                  DROP COLUMN IF EXISTS behaviors_downloaded,
                  DROP COLUMN IF EXISTS behaviors_uploaded;
            SQL);
            DB::statement('DROP TABLE IF EXISTS behavior_promotions;');
            DB::statement('DROP TABLE IF EXISTS behavior_suggestions;');
            DB::statement('DROP TABLE IF EXISTS behavior_correlations;');
            DB::statement('DROP TABLE IF EXISTS behavior_logs;');
            DB::statement('DROP TABLE IF EXISTS behaviors;');
        });
    }
};
