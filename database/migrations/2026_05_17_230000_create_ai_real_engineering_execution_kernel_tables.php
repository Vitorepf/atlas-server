<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_real_execution_worktrees')) {
            Schema::create('ai_real_execution_worktrees', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.worktree.v1');
                $table->string('worktree_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->string('base_path', 700);
                $table->string('branch_name', 220)->nullable()->index();
                $table->string('isolation_mode', 80)->default('sandbox_worktree')->index();
                $table->json('allowed_paths')->nullable();
                $table->json('forbidden_paths')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_patch_runs')) {
            Schema::create('ai_real_execution_patch_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->uuid('worktree_record_id')->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.patch_run.v1');
                $table->string('patch_run_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->string('execution_mode', 80)->default('sandbox_patch')->index();
                $table->json('changed_files')->nullable();
                $table->json('scope_guard')->nullable();
                $table->longText('diff_summary')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('patch_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_test_runs')) {
            Schema::create('ai_real_execution_test_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->uuid('patch_run_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.test_run.v1');
                $table->string('test_run_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('selected_tests')->nullable();
                $table->json('impact_reasoning')->nullable();
                $table->integer('exit_code')->nullable();
                $table->longText('output_excerpt')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('test_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_repair_attempts')) {
            Schema::create('ai_real_execution_repair_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->uuid('patch_run_record_id')->nullable()->index();
                $table->uuid('test_run_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.repair_attempt.v1');
                $table->string('repair_attempt_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->string('failure_class', 100)->index();
                $table->json('failure')->nullable();
                $table->json('repair_plan')->nullable();
                $table->json('changed_files')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('repair_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_forge_handoffs')) {
            Schema::create('ai_real_execution_forge_handoffs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.forge_handoff.v1');
                $table->string('handoff_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('handoff_packet')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('handoff_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_delivery_packs')) {
            Schema::create('ai_real_execution_delivery_packs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.delivery_pack.v1');
                $table->string('delivery_pack_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('summary')->nullable();
                $table->json('changed_files')->nullable();
                $table->json('test_evidence')->nullable();
                $table->json('risk_register')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('delivery_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_rivals_benchmarks')) {
            Schema::create('ai_real_execution_rivals_benchmarks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.rivals_benchmark.v1');
                $table->string('benchmark_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('rivals')->nullable();
                $table->json('comparison_protocol')->nullable();
                $table->boolean('false_claim_blocked')->default(true)->index();
                $table->json('benchmark_candidate')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('benchmark_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_real_execution_certifications')) {
            Schema::create('ai_real_execution_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 140)->default('atlas.ai.real_execution.certification.v1');
                $table->string('certification_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->string('scope', 40)->default('kernel')->index();
                $table->json('checks')->nullable();
                $table->json('blockers')->nullable();
                $table->json('claim_policy')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('certification_hash', 64)->unique();
                $table->timestamp('certified_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_real_execution_certifications');
        Schema::dropIfExists('ai_real_execution_rivals_benchmarks');
        Schema::dropIfExists('ai_real_execution_delivery_packs');
        Schema::dropIfExists('ai_real_execution_forge_handoffs');
        Schema::dropIfExists('ai_real_execution_repair_attempts');
        Schema::dropIfExists('ai_real_execution_test_runs');
        Schema::dropIfExists('ai_real_execution_patch_runs');
        Schema::dropIfExists('ai_real_execution_worktrees');
    }
};
