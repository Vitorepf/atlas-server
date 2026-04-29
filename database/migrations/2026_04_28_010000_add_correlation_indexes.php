<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_passive_signals_type_started_at
              ON passive_signals(signal_type, started_at DESC)
              WHERE deleted_at IS NULL;

            CREATE INDEX IF NOT EXISTS idx_passive_signals_source_type_started_at
              ON passive_signals(source, signal_type, started_at DESC)
              WHERE deleted_at IS NULL;

            CREATE INDEX IF NOT EXISTS idx_passive_signals_type_updated_at
              ON passive_signals(signal_type, updated_at DESC);

            CREATE INDEX IF NOT EXISTS idx_checkins_state_recorded_at
              ON checkins(state, recorded_at DESC)
              WHERE deleted_at IS NULL;

            CREATE INDEX IF NOT EXISTS idx_checkins_energy_mood_recorded_at
              ON checkins(energy_level, mood_level, recorded_at DESC)
              WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_checkins_energy_mood_recorded_at;
            DROP INDEX IF EXISTS idx_checkins_state_recorded_at;
            DROP INDEX IF EXISTS idx_passive_signals_type_updated_at;
            DROP INDEX IF EXISTS idx_passive_signals_source_type_started_at;
            DROP INDEX IF EXISTS idx_passive_signals_type_started_at;
        SQL);
    }
};
