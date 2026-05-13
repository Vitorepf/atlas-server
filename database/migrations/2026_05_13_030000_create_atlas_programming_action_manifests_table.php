<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_action_manifests')) {
            return;
        }

        Schema::create('atlas_programming_action_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action_id', 120)->unique();
            $table->string('plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->string('tool', 120)->index();
            $table->boolean('programming_tool')->default(false)->index();
            $table->string('permission_mode', 20)->index();
            $table->boolean('dry_run')->default(false)->index();
            $table->string('gate_effect', 40)->index();
            $table->string('next_action', 40)->index();
            $table->json('changed_files_json')->default('[]');
            $table->json('rollback_json')->default('{}');
            $table->json('inputs_json')->default('{}');
            $table->json('outputs_json')->default('{}');
            $table->json('payload_json')->default('{}');
            $table->string('payload_hash', 64)->index();
            $table->timestamps();

            $table->index(['plan_id', 'stage', 'created_at'], 'idx_atlas_prog_action_manifests_plan_stage');
            $table->index(['tool', 'gate_effect', 'created_at'], 'idx_atlas_prog_action_manifests_tool_gate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_action_manifests');
    }
};
