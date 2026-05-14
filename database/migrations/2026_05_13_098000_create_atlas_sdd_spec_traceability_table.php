<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_spec_traceability')) {
            return;
        }

        // Many-to-many traceability graph linking spec → requirement →
        // acceptance criterion → task → file → test → evidence event.
        Schema::create('atlas_spec_traceability', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->index();
            $table->uuid('requirement_id')->nullable()->index();
            $table->uuid('acceptance_criteria_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('file_path', 500)->nullable();
            $table->string('test_path', 500)->nullable();
            $table->uuid('evidence_event_id')->nullable()->index();
            $table->string('link_type', 64)->default('implements')->index();
            $table->string('confidence', 32)->default('confirmed')->index();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->index(['spec_id', 'requirement_id'], 'idx_atlas_trace_spec_req');
            $table->index(['task_id', 'file_path'], 'idx_atlas_trace_task_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_spec_traceability');
    }
};
