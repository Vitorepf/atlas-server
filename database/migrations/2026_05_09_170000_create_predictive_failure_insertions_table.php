<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('predictive_failure_insertions')) {
            Schema::create('predictive_failure_insertions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('envelope_id');
                $table->uuid('target_knowledge_node_id');
                $table->string('domain', 64);
                $table->json('signals_used');
                $table->decimal('predicted_failure_probability', 4, 3);
                $table->string('calibration_band', 16);
                $table->string('predicted_failure_signature_key', 200)->nullable();
                $table->json('problem_payload');
                $table->string('source_type', 32);
                $table->timestamp('inserted_at');
                $table->string('outcome', 32)->nullable();
                $table->json('actual_failure_signature')->nullable();
                $table->timestamp('outcome_recorded_at')->nullable();
                $table->decimal('prediction_calibration_error', 4, 3)->nullable();
                $table->timestamps();
                $table->index(['domain', 'inserted_at']);
                $table->index('outcome');
            });
        }

        if (! Schema::hasTable('predictive_failure_calibration_metrics')) {
            Schema::create('predictive_failure_calibration_metrics', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 64);
                $table->timestamp('window_start');
                $table->timestamp('window_end');
                $table->unsignedInteger('total_insertions')->default(0);
                $table->unsignedInteger('outcomes_recorded')->default(0);
                $table->decimal('avg_calibration_error', 4, 3)->nullable();
                $table->decimal('brier_score', 4, 3)->nullable();
                $table->json('insertion_distribution_by_band')->nullable();
                $table->timestamp('computed_at');
                $table->timestamps();
                $table->index(['domain', 'window_end']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('predictive_failure_calibration_metrics');
        Schema::dropIfExists('predictive_failure_insertions');
    }
};
