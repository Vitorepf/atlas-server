<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS atlas_project_steps (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              project_id UUID NOT NULL REFERENCES atlas_projects(id) ON DELETE CASCADE,
              active_task_id UUID REFERENCES atlas_tasks(id) ON DELETE SET NULL,
              step_order INTEGER NOT NULL,
              title TEXT NOT NULL,
              description TEXT,
              status TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'active', 'done', 'skipped', 'blocked')),
              step_type TEXT NOT NULL DEFAULT 'action'
                CHECK (step_type IN ('phase', 'action', 'milestone', 'review')),
              expected_output TEXT,
              acceptance_criteria TEXT,
              estimated_minutes INTEGER NOT NULL DEFAULT 25
                CHECK (estimated_minutes BETWEEN 5 AND 480),
              energy_required TEXT NOT NULL DEFAULT 'medium'
                CHECK (energy_required IN ('low', 'medium', 'high')),
              friction_level SMALLINT NOT NULL DEFAULT 50
                CHECK (friction_level BETWEEN 0 AND 100),
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              started_at TIMESTAMPTZ,
              completed_at TIMESTAMPTZ,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              UNIQUE(project_id, step_order)
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_project_steps_project_status
              ON atlas_project_steps(project_id, status, step_order);
            CREATE INDEX IF NOT EXISTS idx_atlas_project_steps_active_task
              ON atlas_project_steps(active_task_id);

            DROP TRIGGER IF EXISTS trg_atlas_project_steps_updated_at ON atlas_project_steps;
            CREATE TRIGGER trg_atlas_project_steps_updated_at
            BEFORE UPDATE ON atlas_project_steps
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            ALTER TABLE atlas_projects ADD COLUMN IF NOT EXISTS current_step_id UUID;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS project_step_id UUID;

            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'atlas_projects_current_step_id_fk'
              ) THEN
                ALTER TABLE atlas_projects
                ADD CONSTRAINT atlas_projects_current_step_id_fk
                FOREIGN KEY (current_step_id) REFERENCES atlas_project_steps(id)
                ON DELETE SET NULL;
              END IF;

              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'atlas_tasks_project_step_id_fk'
              ) THEN
                ALTER TABLE atlas_tasks
                ADD CONSTRAINT atlas_tasks_project_step_id_fk
                FOREIGN KEY (project_step_id) REFERENCES atlas_project_steps(id)
                ON DELETE SET NULL;
              END IF;
            END $$;

            CREATE INDEX IF NOT EXISTS idx_atlas_projects_current_step
              ON atlas_projects(current_step_id)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_project_step
              ON atlas_tasks(project_step_id)
              WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_project_step_id_fk;
            ALTER TABLE atlas_projects DROP CONSTRAINT IF EXISTS atlas_projects_current_step_id_fk;

            DROP INDEX IF EXISTS idx_atlas_tasks_project_step;
            DROP INDEX IF EXISTS idx_atlas_projects_current_step;

            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS project_step_id;
            ALTER TABLE atlas_projects DROP COLUMN IF EXISTS current_step_id;

            DROP TRIGGER IF EXISTS trg_atlas_project_steps_updated_at ON atlas_project_steps;
            DROP TABLE IF EXISTS atlas_project_steps;
        SQL);
    }
};
