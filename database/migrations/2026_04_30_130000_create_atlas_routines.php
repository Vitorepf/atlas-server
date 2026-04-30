<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS atlas_routines (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              title TEXT NOT NULL,
              description TEXT,
              status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active', 'paused', 'archived')),
              domain TEXT NOT NULL REFERENCES atlas_domains(slug) ON UPDATE CASCADE,
              source_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
              project_id UUID REFERENCES atlas_projects(id) ON DELETE SET NULL,
              frequency TEXT NOT NULL DEFAULT 'daily'
                CHECK (frequency IN ('daily', 'weekdays', 'weekly', 'custom')),
              weekdays JSONB NOT NULL DEFAULT '[]'::jsonb,
              timezone TEXT NOT NULL DEFAULT 'UTC',
              preferred_time TEXT,
              estimated_minutes INTEGER NOT NULL DEFAULT 25
                CHECK (estimated_minutes BETWEEN 5 AND 480),
              energy_required TEXT NOT NULL DEFAULT 'medium'
                CHECK (energy_required IN ('low', 'medium', 'high')),
              priority TEXT NOT NULL DEFAULT 'normal'
                CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
              execution_mode TEXT NOT NULL DEFAULT 'maintenance'
                CHECK (execution_mode IN ('quick_win', 'deep_work', 'admin', 'study', 'tedious', 'creative', 'decision', 'maintenance', 'recovery')),
              friction_level SMALLINT NOT NULL DEFAULT 45
                CHECK (friction_level BETWEEN 0 AND 100),
              emotional_resistance SMALLINT NOT NULL DEFAULT 35
                CHECK (emotional_resistance BETWEEN 0 AND 100),
              clarity_level SMALLINT NOT NULL DEFAULT 75
                CHECK (clarity_level BETWEEN 0 AND 100),
              starter_step TEXT,
              minimum_viable_action TEXT,
              if_then_plan TEXT,
              reward_hint TEXT,
              next_occurrence_date DATE,
              last_generated_for_date DATE,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ,
              CONSTRAINT atlas_routines_preferred_time_check
                CHECK (preferred_time IS NULL OR preferred_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
              CONSTRAINT atlas_routines_weekdays_array_check
                CHECK (jsonb_typeof(weekdays) = 'array')
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_routines_domain_status
              ON atlas_routines(domain, status, next_occurrence_date)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_routines_next_occurrence
              ON atlas_routines(next_occurrence_date)
              WHERE deleted_at IS NULL AND status = 'active';
            CREATE INDEX IF NOT EXISTS idx_atlas_routines_source_capture
              ON atlas_routines(source_capture_id)
              WHERE deleted_at IS NULL;

            DROP TRIGGER IF EXISTS trg_atlas_routines_updated_at ON atlas_routines;
            CREATE TRIGGER trg_atlas_routines_updated_at
            BEFORE UPDATE ON atlas_routines
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS atlas_routine_events (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              routine_id UUID NOT NULL REFERENCES atlas_routines(id) ON DELETE CASCADE,
              event_type TEXT NOT NULL,
              source TEXT NOT NULL DEFAULT 'app',
              payload JSONB NOT NULL DEFAULT '{}'::jsonb,
              occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_routine_events_routine
              ON atlas_routine_events(routine_id, occurred_at DESC);
            CREATE INDEX IF NOT EXISTS idx_atlas_routine_events_type
              ON atlas_routine_events(event_type, occurred_at DESC);

            DROP TRIGGER IF EXISTS trg_atlas_routine_events_updated_at ON atlas_routine_events;
            CREATE TRIGGER trg_atlas_routine_events_updated_at
            BEFORE UPDATE ON atlas_routine_events
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS routine_id UUID;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS routine_occurrence_date DATE;

            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'atlas_tasks_routine_id_fk'
              ) THEN
                ALTER TABLE atlas_tasks
                ADD CONSTRAINT atlas_tasks_routine_id_fk
                FOREIGN KEY (routine_id) REFERENCES atlas_routines(id)
                ON DELETE SET NULL;
              END IF;
            END $$;

            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_routine
              ON atlas_tasks(routine_id, routine_occurrence_date)
              WHERE deleted_at IS NULL;

            CREATE UNIQUE INDEX IF NOT EXISTS idx_atlas_tasks_unique_routine_occurrence
              ON atlas_tasks(routine_id, routine_occurrence_date)
              WHERE deleted_at IS NULL AND routine_id IS NOT NULL AND routine_occurrence_date IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_atlas_tasks_unique_routine_occurrence;
            DROP INDEX IF EXISTS idx_atlas_tasks_routine;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_routine_id_fk;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS routine_occurrence_date;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS routine_id;

            DROP TRIGGER IF EXISTS trg_atlas_routine_events_updated_at ON atlas_routine_events;
            DROP TRIGGER IF EXISTS trg_atlas_routines_updated_at ON atlas_routines;
            DROP TABLE IF EXISTS atlas_routine_events;
            DROP TABLE IF EXISTS atlas_routines;
        SQL);
    }
};
