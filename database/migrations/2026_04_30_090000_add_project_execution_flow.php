<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS project_type TEXT NOT NULL DEFAULT 'personal';
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS desired_outcome TEXT;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS minimum_viable_outcome TEXT;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS definition_of_done TEXT;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS why_now TEXT;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS deadline_at TIMESTAMPTZ;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS deadline_kind TEXT NOT NULL DEFAULT 'none';
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS priority TEXT NOT NULL DEFAULT 'normal';
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS energy_profile TEXT NOT NULL DEFAULT 'mixed';
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS avoidance_reason TEXT NOT NULL DEFAULT 'unknown';
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS active_next_task_id UUID;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS last_touched_at TIMESTAMPTZ;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS next_review_at TIMESTAMPTZ;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ;
            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS paused_until TIMESTAMPTZ;

            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS project_id UUID;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS execution_mode TEXT NOT NULL DEFAULT 'quick_win';
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS friction_level SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS emotional_resistance SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS clarity_level SMALLINT NOT NULL DEFAULT 60;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS starter_step TEXT;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS minimum_viable_action TEXT;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS if_then_plan TEXT;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS reward_hint TEXT;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS failure_reason_last TEXT;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS attempt_count INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS recovery_count INTEGER NOT NULL DEFAULT 0;

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_status_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_status_check
              CHECK (status IN ('active', 'paused', 'blocked', 'waiting', 'completed', 'archived'));

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_project_type_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_project_type_check
              CHECK (project_type IN ('study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate'));

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_deadline_kind_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_deadline_kind_check
              CHECK (deadline_kind IN ('real', 'desired', 'artificial', 'none'));

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_priority_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_priority_check
              CHECK (priority IN ('low', 'normal', 'high', 'urgent'));

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_energy_profile_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_energy_profile_check
              CHECK (energy_profile IN ('low', 'medium', 'high', 'mixed'));

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_avoidance_reason_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_avoidance_reason_check
              CHECK (avoidance_reason IN ('unclear', 'boring', 'too_large', 'scary', 'perfectionism', 'no_reward', 'low_energy', 'dependency', 'unknown'));

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_execution_mode_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_execution_mode_check
              CHECK (execution_mode IN ('quick_win', 'deep_work', 'admin', 'study', 'tedious', 'creative', 'decision', 'maintenance', 'recovery'));

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_friction_level_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_friction_level_check
              CHECK (friction_level BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_emotional_resistance_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_emotional_resistance_check
              CHECK (emotional_resistance BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_clarity_level_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_clarity_level_check
              CHECK (clarity_level BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_attempt_count_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_attempt_count_check
              CHECK (attempt_count >= 0);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_recovery_count_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_recovery_count_check
              CHECK (recovery_count >= 0);

            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'atlas_tasks_project_id_fk'
              ) THEN
                ALTER TABLE atlas_tasks
                ADD CONSTRAINT atlas_tasks_project_id_fk
                FOREIGN KEY (project_id) REFERENCES atlas_projects(id)
                ON DELETE SET NULL;
              END IF;

              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'atlas_projects_active_next_task_id_fk'
              ) THEN
                ALTER TABLE atlas_projects
                ADD CONSTRAINT atlas_projects_active_next_task_id_fk
                FOREIGN KEY (active_next_task_id) REFERENCES atlas_tasks(id)
                ON DELETE SET NULL;
              END IF;
            END $$;

            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_project_status
              ON atlas_tasks(project_id, status, priority_score DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_execution_mode
              ON atlas_tasks(execution_mode, friction_level DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_projects_active_next_task
              ON atlas_projects(active_next_task_id)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_projects_review
              ON atlas_projects(status, next_review_at, last_touched_at)
              WHERE deleted_at IS NULL;

            CREATE TABLE IF NOT EXISTS atlas_project_events (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              project_id UUID NOT NULL REFERENCES atlas_projects(id) ON DELETE CASCADE,
              event_type TEXT NOT NULL,
              source TEXT NOT NULL DEFAULT 'app',
              payload JSONB NOT NULL DEFAULT '{}'::jsonb,
              occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_project_events_project
              ON atlas_project_events(project_id, occurred_at DESC);
            CREATE INDEX IF NOT EXISTS idx_atlas_project_events_type
              ON atlas_project_events(event_type, occurred_at DESC);

            DROP TRIGGER IF EXISTS trg_atlas_project_events_updated_at ON atlas_project_events;
            CREATE TRIGGER trg_atlas_project_events_updated_at
            BEFORE UPDATE ON atlas_project_events
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_atlas_project_events_updated_at ON atlas_project_events;
            DROP TABLE IF EXISTS atlas_project_events;

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_active_next_task_id_fk;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_project_id_fk;

            DROP INDEX IF EXISTS idx_atlas_projects_review;
            DROP INDEX IF EXISTS idx_atlas_projects_active_next_task;
            DROP INDEX IF EXISTS idx_atlas_tasks_execution_mode;
            DROP INDEX IF EXISTS idx_atlas_tasks_project_status;

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_recovery_count_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_attempt_count_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_clarity_level_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_emotional_resistance_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_friction_level_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_execution_mode_check;

            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_avoidance_reason_check;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_energy_profile_check;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_priority_check;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_deadline_kind_check;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_project_type_check;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_status_check;
            ALTER TABLE atlas_projects ADD CONSTRAINT atlas_projects_status_check
              CHECK (status IN ('active', 'paused', 'completed', 'archived'));

            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS recovery_count;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS attempt_count;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS failure_reason_last;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS reward_hint;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS if_then_plan;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS minimum_viable_action;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS starter_step;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS clarity_level;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS emotional_resistance;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS friction_level;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS execution_mode;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS project_id;

            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS paused_until;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS completed_at;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS next_review_at;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS last_touched_at;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS active_next_task_id;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS avoidance_reason;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS energy_profile;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS priority;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS deadline_kind;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS deadline_at;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS why_now;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS definition_of_done;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS minimum_viable_outcome;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS desired_outcome;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS project_type;
        SQL);
    }
};
