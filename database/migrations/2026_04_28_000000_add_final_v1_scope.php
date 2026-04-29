<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE checkins
              ADD COLUMN energy_level SMALLINT,
              ADD COLUMN mood_level SMALLINT;

            ALTER TABLE checkins
              ADD CONSTRAINT checkins_energy_level_range
                CHECK (energy_level IS NULL OR energy_level BETWEEN 1 AND 5),
              ADD CONSTRAINT checkins_mood_level_range
                CHECK (mood_level IS NULL OR mood_level BETWEEN 1 AND 5);

            CREATE INDEX idx_checkins_energy_level ON checkins(energy_level) WHERE deleted_at IS NULL;
            CREATE INDEX idx_checkins_mood_level ON checkins(mood_level) WHERE deleted_at IS NULL;

            CREATE TABLE passive_signals (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              client_id UUID NOT NULL UNIQUE,

              source TEXT NOT NULL CHECK (source IN ('healthkit', 'rize')),
              signal_type TEXT NOT NULL,

              value_numeric NUMERIC(14, 4),
              value_text TEXT,
              unit TEXT,

              started_at TIMESTAMPTZ NOT NULL,
              ended_at TIMESTAMPTZ,
              recorded_timezone TEXT NOT NULL,

              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ,

              CONSTRAINT passive_signals_ended_after_started
                CHECK (ended_at IS NULL OR ended_at >= started_at)
            );

            CREATE INDEX idx_passive_signals_started_at
              ON passive_signals(started_at DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_passive_signals_source
              ON passive_signals(source)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_passive_signals_signal_type
              ON passive_signals(signal_type)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_passive_signals_updated_at
              ON passive_signals(updated_at DESC);
            CREATE INDEX idx_passive_signals_client_id
              ON passive_signals(client_id);

            CREATE TRIGGER trg_passive_signals_updated_at
            BEFORE UPDATE ON passive_signals
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE daily_missions (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

              mission_date DATE NOT NULL UNIQUE,
              mission_timezone TEXT NOT NULL,
              title TEXT NOT NULL,
              detail TEXT,
              status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active', 'done', 'skipped')),

              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,

              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ
            );

            CREATE INDEX idx_daily_missions_date
              ON daily_missions(mission_date DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX idx_daily_missions_updated_at
              ON daily_missions(updated_at DESC);

            CREATE TRIGGER trg_daily_missions_updated_at
            BEFORE UPDATE ON daily_missions
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            ALTER TABLE sync_log
              ADD COLUMN passive_signals_uploaded INTEGER NOT NULL DEFAULT 0 CHECK (passive_signals_uploaded >= 0),
              ADD COLUMN passive_signals_downloaded INTEGER NOT NULL DEFAULT 0 CHECK (passive_signals_downloaded >= 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_log
              DROP COLUMN IF EXISTS passive_signals_downloaded,
              DROP COLUMN IF EXISTS passive_signals_uploaded;

            DROP TABLE IF EXISTS daily_missions;
            DROP TABLE IF EXISTS passive_signals;

            DROP INDEX IF EXISTS idx_checkins_mood_level;
            DROP INDEX IF EXISTS idx_checkins_energy_level;

            ALTER TABLE checkins
              DROP CONSTRAINT IF EXISTS checkins_mood_level_range,
              DROP CONSTRAINT IF EXISTS checkins_energy_level_range,
              DROP COLUMN IF EXISTS mood_level,
              DROP COLUMN IF EXISTS energy_level;
        SQL);
    }
};
