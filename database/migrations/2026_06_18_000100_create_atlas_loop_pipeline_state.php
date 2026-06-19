<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP-OS · Fase 2 · Slice 8 — atlas_loop_pipeline_state (§10.1). The carrier for the SEVERED async
 * PROJECTION stage: produce() dispatches a row and returns in microseconds; a separate drainer claims the
 * row (own lease) and runs the unbounded designer↔critic loop, so the zombie-detector never reclaims a
 * "0-grinds" worker. Idempotent (objective_id unique); never `INSERT INTO migrations` by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_loop_pipeline_state')) {
            return;
        }

        Schema::create('atlas_loop_pipeline_state', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('campaign_id')->index();
            $table->string('objective_id')->unique();          // one row per objective
            $table->string('stage')->default('projection');    // understand|identify|projection|…|parked
            $table->string('claim_owner')->nullable();          // async worker lease holder
            $table->timestamp('lease_expires_at')->nullable();  // zombie-detector reclaims only when expired
            $table->json('checkpoint')->nullable();             // resumable mid-pipeline state (round, etc.)
            $table->double('accrued_ev')->default(0.0);         // resume priority (ahead of fresh objectives)
            $table->json('obligation_set')->nullable();         // §10.6 typed-obligation set
            $table->timestamps();

            $table->index(['accrued_ev']);            // resume-priority scan (DESC applied at query time)
            $table->index(['stage', 'lease_expires_at']); // claim scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_loop_pipeline_state');
    }
};
