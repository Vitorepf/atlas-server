<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE health_snapshots (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              client_id UUID NOT NULL UNIQUE,

              source TEXT NOT NULL DEFAULT 'atlas_app'
                CHECK (source IN ('atlas_app', 'server', 'import')),
              snapshot_date DATE NOT NULL,
              snapshot_timezone TEXT NOT NULL,
              computed_at TIMESTAMPTZ NOT NULL,

              signal_count INTEGER NOT NULL DEFAULT 0 CHECK (signal_count >= 0),

              readiness_score SMALLINT CHECK (readiness_score IS NULL OR readiness_score BETWEEN 0 AND 100),
              current_score SMALLINT CHECK (current_score IS NULL OR current_score BETWEEN 0 AND 100),
              body_score SMALLINT CHECK (body_score IS NULL OR body_score BETWEEN 0 AND 100),
              mind_score SMALLINT CHECK (mind_score IS NULL OR mind_score BETWEEN 0 AND 100),
              drive_score SMALLINT CHECK (drive_score IS NULL OR drive_score BETWEEN 0 AND 100),
              sleep_score SMALLINT CHECK (sleep_score IS NULL OR sleep_score BETWEEN 0 AND 100),
              autonomic_score SMALLINT CHECK (autonomic_score IS NULL OR autonomic_score BETWEEN 0 AND 100),
              load_score SMALLINT CHECK (load_score IS NULL OR load_score BETWEEN 0 AND 100),
              subjective_score SMALLINT CHECK (subjective_score IS NULL OR subjective_score BETWEEN 0 AND 100),
              stability_score SMALLINT CHECK (stability_score IS NULL OR stability_score BETWEEN 0 AND 100),
              confidence NUMERIC(4, 3) CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),

              sleep_duration_hours NUMERIC(6, 3),
              sleep_efficiency NUMERIC(6, 3),
              hrv_ms NUMERIC(8, 3),
              resting_heart_rate_bpm NUMERIC(8, 3),
              respiratory_rate NUMERIC(8, 3),
              wrist_temperature_c NUMERIC(8, 3),

              active_energy_kcal NUMERIC(10, 3),
              basal_energy_kcal NUMERIC(10, 3),
              exercise_minutes NUMERIC(10, 3),
              stand_minutes NUMERIC(10, 3),
              steps NUMERIC(12, 3),
              walking_running_distance_m NUMERIC(12, 3),
              vo2max NUMERIC(8, 3),

              body_mass_kg NUMERIC(8, 3),
              body_fat_percentage NUMERIC(6, 3),
              lean_body_mass_kg NUMERIC(8, 3),
              muscle_mass_percentage NUMERIC(6, 3),
              body_mass_index NUMERIC(6, 3),
              waist_circumference_cm NUMERIC(8, 3),

              energy_level SMALLINT CHECK (energy_level IS NULL OR energy_level BETWEEN 1 AND 5),
              mood_level SMALLINT CHECK (mood_level IS NULL OR mood_level BETWEEN 1 AND 5),
              state TEXT CHECK (state IS NULL OR state IN ('focused', 'disperse', 'blocked', 'pause')),

              metrics JSONB NOT NULL DEFAULT '{}'::jsonb,
              readiness JSONB NOT NULL DEFAULT '{}'::jsonb,
              sleep JSONB NOT NULL DEFAULT '{}'::jsonb,
              recovery JSONB NOT NULL DEFAULT '{}'::jsonb,
              load JSONB NOT NULL DEFAULT '{}'::jsonb,
              subjective JSONB NOT NULL DEFAULT '{}'::jsonb,
              body JSONB NOT NULL DEFAULT '{}'::jsonb,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ,

              CONSTRAINT health_snapshots_source_date_timezone_unique
                UNIQUE (source, snapshot_date, snapshot_timezone)
            );

            CREATE INDEX idx_health_snapshots_date
              ON health_snapshots(snapshot_date DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_health_snapshots_updated_at
              ON health_snapshots(updated_at DESC);
            CREATE INDEX idx_health_snapshots_source_date
              ON health_snapshots(source, snapshot_date DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_health_snapshots_readiness
              ON health_snapshots(readiness_score DESC NULLS LAST, snapshot_date DESC)
              WHERE deleted_at IS NULL;

            CREATE TRIGGER trg_health_snapshots_updated_at
            BEFORE UPDATE ON health_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            ALTER TABLE sync_log
              ADD COLUMN health_snapshots_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (health_snapshots_uploaded >= 0),
              ADD COLUMN health_snapshots_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (health_snapshots_downloaded >= 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_log
              DROP COLUMN IF EXISTS health_snapshots_downloaded,
              DROP COLUMN IF EXISTS health_snapshots_uploaded;

            DROP TABLE IF EXISTS health_snapshots;
        SQL);
    }
};
