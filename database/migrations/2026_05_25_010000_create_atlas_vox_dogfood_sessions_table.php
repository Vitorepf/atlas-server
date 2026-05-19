<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 7.9 (Claude Z) — durable storage for Atlas Vox dogfood session
 * evidence.
 *
 * Distinct from `atlas_vox_rivals_cases`:
 *   - Rivals = head-to-head comparison Vox vs baseline.
 *   - Dogfood = Vitor's diary of REAL Vox usage ("usei hoje, foi assim").
 *
 * Each row represents a single Vox session Vitor actually used in his
 * day. We persist booleans for the dimensions the V3 promotion gate
 * eventually needs (hotkey usage, real STT, governed_execute, regret,
 * eclipse drill) plus a tight free-form `notes` field and a small
 * `metadata` JSON envelope.
 *
 * Hard rules (mirroring the rivals table):
 *   - No raw audio, no PCM, no transcript text, no prompt body.
 *   - `notes` ≤ 1000 chars (enforced at controller).
 *   - `metadata` ≤ 64 KiB JSON (enforced at controller).
 *   - Migrations idempotent; never INSERT INTO migrations manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_vox_dogfood_sessions')) {
            return;
        }

        Schema::create('atlas_vox_dogfood_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('dogfood_session_id', 60)->unique();
            // Optional link to a real Vox session (`VoxEdgeSession.session_id`)
            // that we already have a VOX_INTENT_COMPILED event for. Indexed so
            // future joins can attribute dogfood evidence to ledger events.
            $table->string('vox_session_id', 120)->nullable()->index();
            // mode ∈ {dictation, prompt_polish, intent_compile, governed_execute}
            $table->string('mode', 40)->index();
            // outcome ∈ {success, partial, failed, cancelled}
            $table->string('outcome', 20)->index();
            $table->boolean('used_hotkey')->default(false)->index();
            $table->boolean('used_real_stt')->default(false)->index();
            $table->boolean('used_governed_execute')->default(false)->index();
            $table->boolean('regret_flag')->default(false)->index();
            $table->boolean('eclipse_used')->default(false)->index();
            // Operator-provided started_at; defaults to created_at when absent.
            // Allows backfilling sessions ("ontem testei...") without making
            // the timestamp lie about when the row was inserted.
            $table->timestampTz('started_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // notes are short by contract (≤1000 chars). TEXT keeps schema
            // future-proof; the limit is enforced at the controller boundary.
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            // Composite indexes for the report's rate/window aggregates.
            $table->index(['outcome', 'created_at'], 'atlas_vox_dogfood_outcome_created_idx');
            $table->index(['mode', 'created_at'], 'atlas_vox_dogfood_mode_created_idx');
            $table->index(['created_at'], 'atlas_vox_dogfood_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
    }
};
