<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS planned_for_date DATE;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS planned_start_at TIMESTAMPTZ;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS planned_end_at TIMESTAMPTZ;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS estimated_minutes INTEGER NOT NULL DEFAULT 25;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS energy_required TEXT NOT NULL DEFAULT 'medium';
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS urgency_score SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS impact_score SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS effort_score SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS priority_score SMALLINT NOT NULL DEFAULT 50;
            ALTER TABLE atlas_tasks ADD COLUMN IF NOT EXISTS planning_status TEXT NOT NULL DEFAULT 'unscheduled';

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_estimated_minutes_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_estimated_minutes_check
              CHECK (estimated_minutes BETWEEN 5 AND 480);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_energy_required_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_energy_required_check
              CHECK (energy_required IN ('low', 'medium', 'high'));

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_urgency_score_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_urgency_score_check
              CHECK (urgency_score BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_impact_score_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_impact_score_check
              CHECK (impact_score BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_effort_score_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_effort_score_check
              CHECK (effort_score BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_priority_score_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_priority_score_check
              CHECK (priority_score BETWEEN 0 AND 100);

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_planning_status_check;
            ALTER TABLE atlas_tasks ADD CONSTRAINT atlas_tasks_planning_status_check
              CHECK (planning_status IN ('unscheduled', 'suggested', 'planned', 'scheduled', 'deferred'));

            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_agenda
              ON atlas_tasks(status, planning_status, priority_score DESC, due_at, planned_for_date)
              WHERE deleted_at IS NULL;

            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_planned_for_date
              ON atlas_tasks(planned_for_date, priority_score DESC)
              WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_atlas_tasks_planned_for_date;
            DROP INDEX IF EXISTS idx_atlas_tasks_agenda;

            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_planning_status_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_priority_score_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_effort_score_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_impact_score_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_urgency_score_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_energy_required_check;
            ALTER TABLE atlas_tasks DROP CONSTRAINT IF EXISTS atlas_tasks_estimated_minutes_check;

            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS planning_status;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS priority_score;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS effort_score;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS impact_score;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS urgency_score;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS energy_required;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS estimated_minutes;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS planned_end_at;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS planned_start_at;
            ALTER TABLE atlas_tasks DROP COLUMN IF EXISTS planned_for_date;
        SQL);
    }
};
