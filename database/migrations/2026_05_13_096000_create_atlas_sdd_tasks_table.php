<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_sdd_tasks')) {
            return;
        }

        // Note: a generic `atlas_tasks` already exists in the project for the
        // engineering harness. SDD tasks live in their own table to keep schema
        // independent and the SDD pipeline self-contained.
        Schema::create('atlas_sdd_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id')->index();
            $table->uuid('spec_id')->index();
            $table->string('code', 32)->index();
            $table->string('type', 64)->default('implement')->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('depends_on_json')->default('[]');
            $table->json('allowed_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('acceptance_refs_json')->default('[]');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();

            $table->unique(['plan_id', 'code'], 'idx_atlas_sdd_tasks_plan_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_sdd_tasks');
    }
};
