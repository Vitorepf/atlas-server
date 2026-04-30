<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS atlas_domains (
              slug TEXT PRIMARY KEY,
              label TEXT NOT NULL,
              description TEXT,
              color_light TEXT NOT NULL,
              color_dark TEXT NOT NULL,
              default_sensitivity TEXT NOT NULL DEFAULT 'normal'
                CHECK (default_sensitivity IN ('normal', 'private', 'sensitive')),
              external_ai_policy TEXT NOT NULL DEFAULT 'allow'
                CHECK (external_ai_policy IN ('allow', 'block_private_sensitive', 'block_all')),
              active BOOLEAN NOT NULL DEFAULT TRUE,
              sort_order INTEGER NOT NULL DEFAULT 100,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            DROP TRIGGER IF EXISTS trg_atlas_domains_updated_at ON atlas_domains;
            CREATE TRIGGER trg_atlas_domains_updated_at
            BEFORE UPDATE ON atlas_domains
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS atlas_tasks (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              title TEXT NOT NULL,
              description TEXT,
              status TEXT NOT NULL DEFAULT 'open'
                CHECK (status IN ('open', 'next', 'waiting', 'done', 'archived')),
              priority TEXT NOT NULL DEFAULT 'normal'
                CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
              domain TEXT NOT NULL REFERENCES atlas_domains(slug) ON UPDATE CASCADE,
              source_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
              due_at TIMESTAMPTZ,
              planned_for_date DATE,
              planned_start_at TIMESTAMPTZ,
              planned_end_at TIMESTAMPTZ,
              estimated_minutes INTEGER NOT NULL DEFAULT 25
                CHECK (estimated_minutes BETWEEN 5 AND 480),
              energy_required TEXT NOT NULL DEFAULT 'medium'
                CHECK (energy_required IN ('low', 'medium', 'high')),
              urgency_score SMALLINT NOT NULL DEFAULT 50
                CHECK (urgency_score BETWEEN 0 AND 100),
              impact_score SMALLINT NOT NULL DEFAULT 50
                CHECK (impact_score BETWEEN 0 AND 100),
              effort_score SMALLINT NOT NULL DEFAULT 50
                CHECK (effort_score BETWEEN 0 AND 100),
              priority_score SMALLINT NOT NULL DEFAULT 50
                CHECK (priority_score BETWEEN 0 AND 100),
              planning_status TEXT NOT NULL DEFAULT 'unscheduled'
                CHECK (planning_status IN ('unscheduled', 'suggested', 'planned', 'scheduled', 'deferred')),
              completed_at TIMESTAMPTZ,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_domain_status ON atlas_tasks(domain, status) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_source_capture ON atlas_tasks(source_capture_id) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_due_at ON atlas_tasks(due_at) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_tasks_agenda ON atlas_tasks(status, planning_status, priority_score DESC, due_at, planned_for_date) WHERE deleted_at IS NULL;

            DROP TRIGGER IF EXISTS trg_atlas_tasks_updated_at ON atlas_tasks;
            CREATE TRIGGER trg_atlas_tasks_updated_at
            BEFORE UPDATE ON atlas_tasks
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS atlas_projects (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              title TEXT NOT NULL,
              description TEXT,
              status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active', 'paused', 'completed', 'archived')),
              domain TEXT NOT NULL REFERENCES atlas_domains(slug) ON UPDATE CASCADE,
              source_capture_id UUID REFERENCES captures(id) ON DELETE SET NULL,
              goal TEXT,
              next_action TEXT,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_projects_domain_status ON atlas_projects(domain, status) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_projects_source_capture ON atlas_projects(source_capture_id) WHERE deleted_at IS NULL;

            DROP TRIGGER IF EXISTS trg_atlas_projects_updated_at ON atlas_projects;
            CREATE TRIGGER trg_atlas_projects_updated_at
            BEFORE UPDATE ON atlas_projects
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS capture_links (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              capture_id UUID NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
              target_type TEXT NOT NULL
                CHECK (target_type IN ('semantic_note', 'semantic_curation_proposal', 'task', 'project', 'hypothesis', 'external')),
              target_id UUID,
              target_title TEXT,
              relation_type TEXT NOT NULL DEFAULT 'triage_destination',
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX IF NOT EXISTS idx_capture_links_unique_target
              ON capture_links(capture_id, target_type, target_id, relation_type)
              WHERE target_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_capture_links_capture ON capture_links(capture_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_capture_links_target ON capture_links(target_type, target_id);

            DROP TRIGGER IF EXISTS trg_capture_links_updated_at ON capture_links;
            CREATE TRIGGER trg_capture_links_updated_at
            BEFORE UPDATE ON capture_links
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS inbox_health_snapshots (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              snapshot_date DATE NOT NULL,
              domain TEXT REFERENCES atlas_domains(slug) ON UPDATE CASCADE,
              open_count INTEGER NOT NULL DEFAULT 0,
              no_destination_count INTEGER NOT NULL DEFAULT 0,
              failed_count INTEGER NOT NULL DEFAULT 0,
              curation_candidate_count INTEGER NOT NULL DEFAULT 0,
              average_age_hours NUMERIC(10, 2) NOT NULL DEFAULT 0,
              oldest_capture_at TIMESTAMPTZ,
              metrics JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              UNIQUE(snapshot_date, domain)
            );

            CREATE INDEX IF NOT EXISTS idx_inbox_health_snapshots_date ON inbox_health_snapshots(snapshot_date DESC, domain);

            DROP TRIGGER IF EXISTS trg_inbox_health_snapshots_updated_at ON inbox_health_snapshots;
            CREATE TRIGGER trg_inbox_health_snapshots_updated_at
            BEFORE UPDATE ON inbox_health_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
        SQL);

        $this->seedDomains();

        DB::unprepared(<<<'SQL'
            UPDATE captures
            SET domain = 'outro'
            WHERE domain IS NULL OR BTRIM(domain) = '';

            ALTER TABLE captures DROP CONSTRAINT IF EXISTS captures_domain_check;

            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'captures_domain_fk'
              ) THEN
                ALTER TABLE captures
                ADD CONSTRAINT captures_domain_fk
                FOREIGN KEY (domain) REFERENCES atlas_domains(slug)
                ON UPDATE CASCADE
                DEFERRABLE INITIALLY IMMEDIATE;
              END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE captures DROP CONSTRAINT IF EXISTS captures_domain_fk;
            DROP TABLE IF EXISTS inbox_health_snapshots;
            DROP TABLE IF EXISTS capture_links;
            DROP TABLE IF EXISTS atlas_projects;
            DROP TABLE IF EXISTS atlas_tasks;
            DROP TABLE IF EXISTS atlas_domains;
        SQL);
    }

    private function seedDomains(): void
    {
        $domains = config('atlas.domains.defaults', []);

        foreach ($domains as $domain) {
            DB::table('atlas_domains')->updateOrInsert(
                ['slug' => $domain['slug']],
                [
                    'label' => $domain['label'],
                    'description' => $domain['description'] ?? null,
                    'color_light' => $domain['color_light'],
                    'color_dark' => $domain['color_dark'],
                    'default_sensitivity' => $domain['default_sensitivity'],
                    'external_ai_policy' => $domain['external_ai_policy'] ?? 'allow',
                    'active' => (bool) ($domain['active'] ?? true),
                    'sort_order' => (int) ($domain['sort_order'] ?? 100),
                    'metadata' => json_encode($domain['metadata'] ?? [], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO atlas_domains (
              slug,
              label,
              description,
              color_light,
              color_dark,
              default_sensitivity,
              external_ai_policy,
              active,
              sort_order,
              metadata,
              created_at,
              updated_at
            )
            SELECT DISTINCT
              captures.domain,
              INITCAP(REPLACE(REPLACE(captures.domain, '-', ' '), '_', ' ')),
              'Dominio existente importado a partir das capturas antes do registro central.',
              '#1B3A57',
              '#6892B5',
              'normal',
              'allow',
              TRUE,
              500,
              '{"imported_from_existing_captures": true}'::jsonb,
              NOW(),
              NOW()
            FROM captures
            WHERE captures.domain IS NOT NULL
              AND captures.domain <> ''
              AND NOT EXISTS (
                SELECT 1 FROM atlas_domains WHERE atlas_domains.slug = captures.domain
              );
        SQL);
    }
};
