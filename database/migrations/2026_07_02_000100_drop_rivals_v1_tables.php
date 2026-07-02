<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rivals 1.0 retirement (Slice 6 do rebuild — ver
 * docs/engineering-knowledge-base/atlas-rivals2-rebuild-map-v1.md §4).
 * Nenhuma das 5 tabelas sobrevive; o Rivals 2.0 usa JSONL append-only
 * em storage/atlas/rivals2/ e não cria DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('atlas_strategy_rivals_cases');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('ai_rivals_shadow_runs');
        Schema::dropIfExists('ai_real_execution_rivals_benchmarks');
    }

    public function down(): void
    {
        // Intencional: sem recriação. Rivals 1.0 foi aposentado em definitivo;
        // restauração exigiria as migrations originais de criação (histórico git).
    }
};
