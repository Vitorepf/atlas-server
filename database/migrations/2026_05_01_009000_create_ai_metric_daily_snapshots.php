<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_metric_daily_snapshots')) {
            return;
        }

        Schema::create('ai_metric_daily_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('snapshot_date');
            $table->string('metric', 80);
            $table->string('aggregator_version', 64);
            $table->integer('n_traces')->default(0);
            $table->double('value_mean')->nullable();
            $table->double('value_p50')->nullable();
            $table->double('value_p95')->nullable();
            $table->double('value_sum')->nullable();
            $table->double('value_rate')->nullable();
            $table->integer('rate_numerator')->nullable();
            $table->integer('rate_denominator')->nullable();
            $table->json('distribution_sample')->nullable();
            $table->json('ewma_state')->nullable();
            $table->timestamp('computed_at')->useCurrent();

            $table->unique(['snapshot_date', 'metric'], 'uniq_ai_metric_snapshot_date_metric');
            $table->index(['metric', 'snapshot_date'], 'idx_ai_metric_snapshot_metric_date');
            $table->index('aggregator_version', 'idx_ai_metric_snapshot_agg_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metric_daily_snapshots');
    }
};
