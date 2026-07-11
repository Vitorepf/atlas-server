<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_memory_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type', 80)->index();
            $table->string('source_id', 240)->nullable()->index();
            $table->text('source_path')->nullable();
            $table->string('memory_type', 40)->default('technical_context')->index();
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->text('body');
            $table->json('candidate_payload')->default('{}');
            $table->json('quality_report')->default('{}');
            $table->json('missing_checks')->default('[]');
            $table->string('status', 24)->default('candidate')->index();
            $table->uuid('memory_entry_id')->nullable()->index();
            $table->timestamp('admitted_at')->nullable()->index();
            $table->timestamp('rejected_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_atlas_memory_candidates_status_created');
            $table->index(['memory_type', 'scope_type', 'status'], 'idx_atlas_memory_candidates_type_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_candidates');
    }
};
