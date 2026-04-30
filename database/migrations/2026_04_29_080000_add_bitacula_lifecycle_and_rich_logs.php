<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD COLUMN IF NOT EXISTS lifecycle_status TEXT NOT NULL DEFAULT 'active',
                  ADD COLUMN IF NOT EXISTS paused_until TIMESTAMPTZ,
                  ADD COLUMN IF NOT EXISTS last_prompted_at DATE,
                  ADD COLUMN IF NOT EXISTS prompt_cadence_days INTEGER NOT NULL DEFAULT 1,
                  ADD COLUMN IF NOT EXISTS auto_suppress_reason TEXT;
            SQL);

            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_lifecycle_status_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_lifecycle_status_check
                  CHECK (lifecycle_status IN ('active', 'baseline', 'paused', 'dormant', 'experiment', 'manual_only'));
            SQL);

            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_prompt_cadence_days_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_prompt_cadence_days_check
                  CHECK (prompt_cadence_days BETWEEN 1 AND 30);
            SQL);

            DB::statement(<<<'SQL'
                UPDATE behaviors
                SET lifecycle_status = CASE
                    WHEN archived_at IS NOT NULL THEN 'manual_only'
                    WHEN show_in_morning_briefing = FALSE THEN 'manual_only'
                    ELSE lifecycle_status
                END;
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE behavior_logs
                  ADD COLUMN IF NOT EXISTS occurred_at TIMESTAMPTZ,
                  ADD COLUMN IF NOT EXISTS occurred_timezone TEXT,
                  ADD COLUMN IF NOT EXISTS quantity_numeric NUMERIC(10, 3),
                  ADD COLUMN IF NOT EXISTS quantity_unit TEXT,
                  ADD COLUMN IF NOT EXISTS intensity INTEGER,
                  ADD COLUMN IF NOT EXISTS context JSONB NOT NULL DEFAULT '{}'::jsonb;
            SQL);

            DB::statement('ALTER TABLE behavior_logs DROP CONSTRAINT IF EXISTS behavior_logs_intensity_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behavior_logs
                  ADD CONSTRAINT behavior_logs_intensity_check
                  CHECK (intensity IS NULL OR intensity BETWEEN 1 AND 5);
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE behavior_logs DROP CONSTRAINT IF EXISTS behavior_logs_intensity_check;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS context;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS intensity;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS quantity_unit;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS quantity_numeric;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS occurred_timezone;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS occurred_at;');

            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_prompt_cadence_days_check;');
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_lifecycle_status_check;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS auto_suppress_reason;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS prompt_cadence_days;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS last_prompted_at;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS paused_until;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS lifecycle_status;');
        });
    }
};
