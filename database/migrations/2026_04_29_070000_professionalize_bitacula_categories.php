<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROFESSIONAL_CATEGORIES = "'substancias', 'alimentacao', 'sono_ritmo', 'treino_movimento', 'recuperacao', 'digital', 'trabalho_cognicao', 'relacional', 'saude_sintoma', 'ambiente_rotina', 'outro', 'bebida', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'saude'";

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_category_check;');

            DB::statement(<<<'SQL'
                UPDATE behaviors
                SET category = CASE category
                    WHEN 'bebida' THEN 'substancias'
                    WHEN 'suplemento' THEN 'substancias'
                    WHEN 'sono' THEN 'sono_ritmo'
                    WHEN 'treino' THEN 'treino_movimento'
                    WHEN 'trabalho' THEN 'trabalho_cognicao'
                    WHEN 'conflito' THEN 'relacional'
                    WHEN 'social' THEN 'relacional'
                    WHEN 'saude' THEN 'saude_sintoma'
                    ELSE category
                END
                WHERE category IN ('bebida', 'suplemento', 'sono', 'treino', 'trabalho', 'conflito', 'social', 'saude');
            SQL);

            DB::statement(sprintf(
                'ALTER TABLE behaviors ADD CONSTRAINT behaviors_category_check CHECK (category IN (%s));',
                self::PROFESSIONAL_CATEGORIES,
            ));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE behaviors DROP CONSTRAINT IF EXISTS behaviors_category_check;');

            DB::statement(<<<'SQL'
                UPDATE behaviors
                SET category = CASE category
                    WHEN 'substancias' THEN 'bebida'
                    WHEN 'sono_ritmo' THEN 'sono'
                    WHEN 'treino_movimento' THEN 'treino'
                    WHEN 'trabalho_cognicao' THEN 'trabalho'
                    WHEN 'saude_sintoma' THEN 'saude'
                    WHEN 'ambiente_rotina' THEN 'outro'
                    WHEN 'recuperacao' THEN 'outro'
                    ELSE category
                END
                WHERE category IN ('substancias', 'sono_ritmo', 'treino_movimento', 'trabalho_cognicao', 'saude_sintoma', 'ambiente_rotina', 'recuperacao');
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE behaviors
                  ADD CONSTRAINT behaviors_category_check
                  CHECK (category IN ('bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'digital', 'relacional', 'saude', 'outro'));
            SQL);
        });
    }
};
