<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_harnessability_calibrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('sample_limit')->default(300);
            $table->unsignedInteger('sample_count')->default(0);
            $table->string('confidence', 24)->default('none')->index();
            $table->json('sample_window_json')->default('{}');
            $table->json('current_thresholds_json')->default('{}');
            $table->json('recommended_thresholds_json')->default('{}');
            $table->json('bucket_metrics_json')->default('{}');
            $table->json('outcome_metrics_json')->default('{}');
            $table->json('recommendations_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('calibrated_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_harnessability_calibrations');
    }
};
