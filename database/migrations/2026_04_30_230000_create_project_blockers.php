<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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

            CREATE TABLE IF NOT EXISTS atlas_project_blockers (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              project_id UUID NOT NULL REFERENCES atlas_projects(id) ON DELETE CASCADE,
              task_id UUID REFERENCES atlas_tasks(id) ON DELETE SET NULL,
              project_step_id UUID REFERENCES atlas_project_steps(id) ON DELETE SET NULL,
              unblock_task_id UUID REFERENCES atlas_tasks(id) ON DELETE SET NULL,
              status TEXT NOT NULL DEFAULT 'open'
                CHECK (status IN ('open', 'resolved', 'cancelled')),
              severity TEXT NOT NULL DEFAULT 'medium'
                CHECK (severity IN ('low', 'medium', 'high')),
              reason_code TEXT NOT NULL DEFAULT 'other'
                CHECK (reason_code IN ('unclear', 'too_large', 'boring', 'waiting_external', 'missing_resource', 'fear', 'energy', 'technical_unknown', 'decision_needed', 'other')),
              description TEXT NOT NULL,
              unblock_next_action TEXT,
              waiting_on TEXT,
              due_at TIMESTAMPTZ,
              resolved_at TIMESTAMPTZ,
              resolution_note TEXT,
              created_from_event_id UUID REFERENCES atlas_project_events(id) ON DELETE SET NULL,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_project_blockers_project_status
              ON atlas_project_blockers(project_id, status, severity, updated_at DESC);
            CREATE INDEX IF NOT EXISTS idx_atlas_project_blockers_task_status
              ON atlas_project_blockers(task_id, status)
              WHERE task_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_project_blockers_step_status
              ON atlas_project_blockers(project_step_id, status)
              WHERE project_step_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_project_blockers_reason
              ON atlas_project_blockers(reason_code, status);

            DROP TRIGGER IF EXISTS trg_atlas_project_blockers_updated_at ON atlas_project_blockers;
            CREATE TRIGGER trg_atlas_project_blockers_updated_at
            BEFORE UPDATE ON atlas_project_blockers
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_atlas_project_blockers_updated_at ON atlas_project_blockers;
            DROP TABLE IF EXISTS atlas_project_blockers;
        SQL);
    }
};
