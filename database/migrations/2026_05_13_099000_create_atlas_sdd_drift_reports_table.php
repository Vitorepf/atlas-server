<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_sdd_drift_reports')) {
            return;
        }

        Schema::create('atlas_sdd_drift_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->nullable()->index();
            $table->uuid('operation_id')->nullable()->index();
            // pass|warn|fail per atlas.sdd_drift.v1
            $table->string('status', 16)->index();
            $table->json('drift_findings_json')->default('[]');
            $table->json('summary_json')->default('{}');
            $table->string('source', 64)->default('scheduled')->index();
            $table->string('detector_version', 32)->default('atlas.sdd_drift.v1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_sdd_drift_reports');
    }
};
