<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS atlas_project_plan_proposals (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              project_id UUID REFERENCES atlas_projects(id) ON DELETE CASCADE,
              source_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
              status TEXT NOT NULL DEFAULT 'pending_review'
                CHECK (status IN ('draft', 'pending_review', 'accepted', 'rejected', 'superseded')),
              proposed_title TEXT NOT NULL,
              planner_version TEXT NOT NULL,
              input_hash TEXT NOT NULL,
              project_type TEXT NOT NULL
                CHECK (project_type IN ('study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate')),
              avoidance_profile TEXT NOT NULL DEFAULT 'unknown',
              desired_outcome TEXT NOT NULL,
              definition_of_done TEXT NOT NULL,
              minimum_useful_result TEXT NOT NULL,
              first_milestone TEXT,
              first_next_action TEXT NOT NULL,
              estimated_energy TEXT NOT NULL DEFAULT 'medium'
                CHECK (estimated_energy IN ('low', 'medium', 'high', 'mixed')),
              estimated_duration_minutes INTEGER NOT NULL DEFAULT 25
                CHECK (estimated_duration_minutes BETWEEN 5 AND 480),
              priority_suggestion TEXT NOT NULL DEFAULT 'normal'
                CHECK (priority_suggestion IN ('low', 'normal', 'high', 'urgent')),
              confidence NUMERIC(4, 3) NOT NULL DEFAULT 0.720,
              phases_json JSONB NOT NULL DEFAULT '[]'::jsonb,
              steps_json JSONB NOT NULL DEFAULT '[]'::jsonb,
              risks_json JSONB NOT NULL DEFAULT '[]'::jsonb,
              questions_json JSONB NOT NULL DEFAULT '[]'::jsonb,
              rationale TEXT NOT NULL,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              accepted_at TIMESTAMPTZ,
              rejected_at TIMESTAMPTZ,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_project_plan_proposals_project_status
              ON atlas_project_plan_proposals(project_id, status, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_project_plan_proposals_capture_status
              ON atlas_project_plan_proposals(source_capture_id, status, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_project_plan_proposals_type_status
              ON atlas_project_plan_proposals(project_type, status, created_at DESC);

            DROP TRIGGER IF EXISTS trg_project_plan_proposals_updated_at ON atlas_project_plan_proposals;
            CREATE TRIGGER trg_project_plan_proposals_updated_at
            BEFORE UPDATE ON atlas_project_plan_proposals
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_project_plan_proposals_updated_at ON atlas_project_plan_proposals;
            DROP TABLE IF EXISTS atlas_project_plan_proposals;
        SQL);
    }
};
