<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_source_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_manual_body_type_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_manual_body_numeric_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_body_value_range_check;

            ALTER TABLE passive_signals
              ADD CONSTRAINT passive_signals_source_check
                CHECK (source IN ('healthkit', 'rize', 'manual')) NOT VALID,
              ADD CONSTRAINT passive_signals_manual_body_type_check
                CHECK (
                  source <> 'manual'
                  OR signal_type IN (
                    'height',
                    'waist_circumference',
                    'body_mass',
                    'body_fat_percentage',
                    'lean_body_mass',
                    'body_mass_index',
                    'muscle_mass_percentage',
                    'skeletal_muscle_percentage',
                    'body_muscle_percentage'
                  )
                ) NOT VALID,
              ADD CONSTRAINT passive_signals_manual_body_numeric_check
                CHECK (source <> 'manual' OR deleted_at IS NOT NULL OR value_numeric IS NOT NULL) NOT VALID,
              ADD CONSTRAINT passive_signals_body_value_range_check
                CHECK (
                  value_numeric IS NULL
                  OR CASE signal_type
                    WHEN 'height' THEN (
                      (unit = 'm' AND value_numeric BETWEEN 0.5 AND 2.5)
                      OR (unit = 'cm' AND value_numeric BETWEEN 50 AND 250)
                      OR (unit IS NULL AND (value_numeric BETWEEN 0.5 AND 2.5 OR value_numeric BETWEEN 50 AND 250))
                    )
                    WHEN 'waist_circumference' THEN (
                      (unit = 'cm' AND value_numeric BETWEEN 30 AND 250)
                      OR (unit = 'm' AND value_numeric BETWEEN 0.3 AND 2.5)
                      OR (unit IS NULL AND (value_numeric BETWEEN 0.3 AND 2.5 OR value_numeric BETWEEN 30 AND 250))
                    )
                    WHEN 'body_fat_percentage' THEN (
                      (unit IS NULL OR unit IN ('%', 'count'))
                      AND (
                        (value_numeric BETWEEN 0.03 AND 1 AND value_numeric * 100 BETWEEN 3 AND 75)
                        OR value_numeric BETWEEN 3 AND 75
                      )
                    )
                    WHEN 'body_mass' THEN (unit IS NULL OR unit = 'kg') AND value_numeric BETWEEN 20 AND 350
                    WHEN 'lean_body_mass' THEN (unit IS NULL OR unit = 'kg') AND value_numeric BETWEEN 10 AND 250
                    WHEN 'body_mass_index' THEN (unit IS NULL OR unit = 'count') AND value_numeric BETWEEN 8 AND 90
                    WHEN 'muscle_mass_percentage' THEN (
                      (unit IS NULL OR unit IN ('%', 'count'))
                      AND (
                        (value_numeric BETWEEN 0.15 AND 1 AND value_numeric * 100 BETWEEN 15 AND 95)
                        OR value_numeric BETWEEN 15 AND 95
                      )
                    )
                    WHEN 'skeletal_muscle_percentage' THEN (
                      (unit IS NULL OR unit IN ('%', 'count'))
                      AND (
                        (value_numeric BETWEEN 0.15 AND 1 AND value_numeric * 100 BETWEEN 15 AND 95)
                        OR value_numeric BETWEEN 15 AND 95
                      )
                    )
                    WHEN 'body_muscle_percentage' THEN (
                      (unit IS NULL OR unit IN ('%', 'count'))
                      AND (
                        (value_numeric BETWEEN 0.15 AND 1 AND value_numeric * 100 BETWEEN 15 AND 95)
                        OR value_numeric BETWEEN 15 AND 95
                      )
                    )
                    ELSE TRUE
                  END
                ) NOT VALID;

            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_mass_kg_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_fat_percentage_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_lean_body_mass_kg_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_muscle_mass_percentage_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_mass_index_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_waist_circumference_cm_range_check;

            ALTER TABLE health_snapshots
              ADD CONSTRAINT health_snapshots_body_mass_kg_range_check
                CHECK (body_mass_kg IS NULL OR body_mass_kg BETWEEN 20 AND 350) NOT VALID,
              ADD CONSTRAINT health_snapshots_body_fat_percentage_range_check
                CHECK (body_fat_percentage IS NULL OR body_fat_percentage BETWEEN 3 AND 75) NOT VALID,
              ADD CONSTRAINT health_snapshots_lean_body_mass_kg_range_check
                CHECK (lean_body_mass_kg IS NULL OR lean_body_mass_kg BETWEEN 10 AND 250) NOT VALID,
              ADD CONSTRAINT health_snapshots_muscle_mass_percentage_range_check
                CHECK (muscle_mass_percentage IS NULL OR muscle_mass_percentage BETWEEN 15 AND 95) NOT VALID,
              ADD CONSTRAINT health_snapshots_body_mass_index_range_check
                CHECK (body_mass_index IS NULL OR body_mass_index BETWEEN 8 AND 90) NOT VALID,
              ADD CONSTRAINT health_snapshots_waist_circumference_cm_range_check
                CHECK (waist_circumference_cm IS NULL OR waist_circumference_cm BETWEEN 30 AND 250) NOT VALID;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_waist_circumference_cm_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_mass_index_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_muscle_mass_percentage_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_lean_body_mass_kg_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_fat_percentage_range_check;
            ALTER TABLE health_snapshots DROP CONSTRAINT IF EXISTS health_snapshots_body_mass_kg_range_check;

            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_body_value_range_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_manual_body_numeric_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_manual_body_type_check;
            ALTER TABLE passive_signals DROP CONSTRAINT IF EXISTS passive_signals_source_check;

            ALTER TABLE passive_signals
              ADD CONSTRAINT passive_signals_source_check
                CHECK (source IN ('healthkit', 'rize')) NOT VALID;
        SQL);
    }
};
