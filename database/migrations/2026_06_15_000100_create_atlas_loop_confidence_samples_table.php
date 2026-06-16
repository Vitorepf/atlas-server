<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Evolution Loop — confidence-calibration flywheel sample store (ITEM10).
 *
 * Durable {predicted, correct} samples the auto-merge feeder appends POST-MERGE
 * (predicted = the cert-time delivery_confidence; correct = the canary verdict).
 * AtlasLoopConfidenceCalibrator::calibrate() consumes these to fit the HONEST arm
 * threshold from real outcomes — the calibrated number an operator may later arm the
 * confidence gate at, never a declared 0.93.
 *
 * Idempotent by construction (Schema::hasTable guard) — migrations are never
 * hand-stamped into the migrations table. Propose-only invariant untouched: this is
 * a NEW telemetry table that nothing in the never-merge layer depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_confidence_samples')) {
            Schema::create('atlas_loop_confidence_samples', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('proposal_id')->nullable()->index();   // the merged proposal the sample came from
                $table->float('predicted');                          // cert-time delivery_confidence (0..1)
                $table->boolean('correct');                          // canary GREEN (ground-truth outcome)
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_confidence_samples')) {
            Schema::dropIfExists('atlas_loop_confidence_samples');
        }
    }
};
