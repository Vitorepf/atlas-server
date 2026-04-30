<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_category_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_category_check
                  CHECK (category IN ('substancias', 'alimentacao', 'sono_ritmo', 'treino_movimento', 'recuperacao', 'digital', 'trabalho_cognicao', 'relacional', 'saude_sintoma', 'ambiente_rotina', 'outro', 'bebida', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'saude'));
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD COLUMN IF NOT EXISTS parent_factor TEXT,
                  ADD COLUMN IF NOT EXISTS factor_condition TEXT,
                  ADD COLUMN IF NOT EXISTS target_outcomes JSONB NOT NULL DEFAULT '[]'::jsonb,
                  ADD COLUMN IF NOT EXISTS expected_lag TEXT,
                  ADD COLUMN IF NOT EXISTS expected_direction TEXT,
                  ADD COLUMN IF NOT EXISTS granularity_level TEXT NOT NULL DEFAULT 'binary',
                  ADD COLUMN IF NOT EXISTS sensitivity_level TEXT NOT NULL DEFAULT 'normal',
                  ADD COLUMN IF NOT EXISTS derived_from JSONB NOT NULL DEFAULT '{}'::jsonb,
                  ADD COLUMN IF NOT EXISTS operator_confirmed BOOLEAN NOT NULL DEFAULT TRUE;
            SQL);

            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_granularity_level_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_granularity_level_check
                  CHECK (granularity_level IN ('binary', 'intensity', 'protocol'));
            SQL);

            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_sensitivity_level_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_sensitivity_level_check
                  CHECK (sensitivity_level IN ('normal', 'sensitive', 'relational', 'medical'));
            SQL);

            DB::statement('CREATE INDEX IF NOT EXISTS idx_behaviors_parent_factor ON behaviors(parent_factor, factor_condition) WHERE deleted_at IS NULL;');

            DB::statement('ALTER TABLE behavior_logs DROP CONSTRAINT IF EXISTS behavior_logs_source_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behavior_logs
                  ADD CONSTRAINT behavior_logs_source_check
                  CHECK (source IN ('morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import', 'inferred'));
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE behavior_logs
                  ADD COLUMN IF NOT EXISTS confidence NUMERIC(4, 3),
                  ADD COLUMN IF NOT EXISTS inferred_by TEXT,
                  ADD COLUMN IF NOT EXISTS consent_snapshot_id UUID;
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS consent_snapshot_id;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS inferred_by;');
            DB::statement('ALTER TABLE behavior_logs DROP COLUMN IF EXISTS confidence;');
            DB::statement('ALTER TABLE behavior_logs DROP CONSTRAINT IF EXISTS behavior_logs_source_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behavior_logs
                  ADD CONSTRAINT behavior_logs_source_check
                  CHECK (source IN ('morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import'));
            SQL);

            DB::statement('DROP INDEX IF EXISTS idx_behaviors_parent_factor;');
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_sensitivity_level_check;');
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_granularity_level_check;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS operator_confirmed;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS derived_from;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS sensitivity_level;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS granularity_level;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS expected_direction;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS expected_lag;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS target_outcomes;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS factor_condition;');
            DB::statement('ALTER TABLE behaviors DROP COLUMN IF EXISTS parent_factor;');
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_category_check;');
            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_category_check
                  CHECK (category IN ('bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'outro'));
            SQL);
        });
    }
};
