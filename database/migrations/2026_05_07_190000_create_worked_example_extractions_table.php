<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worked_example_extractions')) {
            Schema::create('worked_example_extractions', function (Blueprint $table): void {
                $table->id();
                $table->string('source_type', 32);
                $table->string('source_ref', 200);
                $table->json('source_metadata');
                $table->string('extraction_status', 32);
                $table->text('discard_reason')->nullable();
                $table->foreignId('worked_example_id')->nullable()->constrained('worked_examples');
                $table->json('quality_signals')->nullable();
                $table->json('redaction_applied')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'extraction_status']);
                $table->unique(['source_type', 'source_ref'], 'worked_example_extractions_source_unique');
            });
        }

        if (! Schema::hasTable('personal_extraction_jobs')) {
            Schema::create('personal_extraction_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('cadence', 16);
                $table->json('source_filters');
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->json('last_run_summary')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_extraction_jobs');
        Schema::dropIfExists('worked_example_extractions');
    }
};
