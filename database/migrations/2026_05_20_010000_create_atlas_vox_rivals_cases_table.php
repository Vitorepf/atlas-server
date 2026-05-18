<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 7 (Claude O) — durable storage for Atlas Vox rivals cases.
 *
 * Why a table and not cache: rivals cases must survive between sessions
 * (Vitor records them ad-hoc over days/weeks) and must be auditable by
 * `php artisan test` runs of VoxMetricsService against real data. Cache
 * is too volatile, ledger events alone don't model the (vox vs baseline)
 * pair cleanly.
 *
 * Hard rule: this table stores the **human evaluation** of a rivals case.
 * It does NOT carry raw audio, transcript text, prompt body, or any
 * provider payload. `metadata` is a small JSON envelope for free-form
 * audit notes (rivals kind variants, comparison context) — never PCM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_vox_rivals_cases')) {
            return;
        }

        Schema::create('atlas_vox_rivals_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_id', 60)->unique();
            // kind ∈ {wispr_baseline, provider_direct, manual}
            $table->string('kind', 40)->index();
            // mode ∈ {dictation, prompt_polish, intent_compile, governed_execute}
            $table->string('mode', 40)->index();
            $table->string('vox_session_id', 120)->nullable()->index();
            $table->string('vox_intent_id', 120)->nullable()->index();
            $table->string('baseline_label', 240);
            $table->unsignedInteger('baseline_duration_ms')->nullable();
            $table->unsignedInteger('vox_duration_ms')->nullable();
            // 1..5 quality scores. Nullable so cases can record only a
            // preference without forcing a numeric grade.
            $table->unsignedTinyInteger('baseline_score')->nullable();
            $table->unsignedTinyInteger('vox_score')->nullable();
            // preference ∈ {vox, baseline, tie}
            $table->string('preference', 20)->index();
            // -1, 0, +1 — vote on whether Vox's prompt felt better
            $table->tinyInteger('prompt_quality_vote')->nullable();
            $table->boolean('regret_flag')->default(false)->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['mode', 'created_at'], 'atlas_vox_rivals_cases_mode_created_idx');
            $table->index(['kind', 'created_at'], 'atlas_vox_rivals_cases_kind_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
    }
};
