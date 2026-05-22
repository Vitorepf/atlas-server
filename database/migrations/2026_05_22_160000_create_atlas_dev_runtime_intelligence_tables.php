<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_dev_task_packets')) {
            Schema::create('atlas_dev_task_packets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.task_packet.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->text('objective');
                $table->string('task_class', 80)->index();
                $table->string('risk_band', 40)->index();
                $table->string('workspace_slug', 160)->nullable()->index();
                $table->json('allowed_files');
                $table->json('forbidden_files');
                $table->json('context_refs');
                $table->json('expected_files');
                $table->json('suggested_tests');
                $table->json('acceptance_criteria');
                $table->json('required_evidence');
                $table->string('source', 120)->nullable()->index();
                $table->string('task_packet_hash', 64)->unique();
                $table->timestamps();

                $table->unique(['run_id', 'task_id'], 'uniq_atlas_dev_packet_run_task');
            });
        }

        if (! Schema::hasTable('atlas_dev_context_gates')) {
            Schema::create('atlas_dev_context_gates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.context_gate.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->uuid('task_packet_id')->nullable()->index();
                $table->string('status', 40)->index();
                $table->json('missing');
                $table->json('remediation');
                $table->boolean('provider_safe')->default(false)->index();
                $table->string('context_gate_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_dev_failure_capsules')) {
            Schema::create('atlas_dev_failure_capsules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.failure_capsule.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->uuid('task_packet_id')->nullable()->index();
                $table->string('failing_gate', 120)->nullable()->index();
                $table->string('failure_class', 120)->index();
                $table->text('error_excerpt')->nullable();
                $table->json('changed_files');
                $table->text('suggested_repair');
                $table->unsignedTinyInteger('retry_budget')->default(1);
                $table->boolean('escalate_to_forge')->default(false)->index();
                $table->string('failure_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_dev_outcome_memories')) {
            Schema::create('atlas_dev_outcome_memories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.outcome_memory.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->uuid('task_packet_id')->nullable()->index();
                $table->uuid('failure_capsule_id')->nullable()->index();
                $table->string('outcome_status', 40)->index();
                $table->json('evidence_kinds');
                $table->json('selected_tests');
                $table->json('changed_files');
                $table->json('learning_candidates');
                $table->boolean('should_promote_to_aemor')->default(true)->index();
                $table->boolean('human_review_required')->default(false)->index();
                $table->string('outcome_memory_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_dev_run_certifications')) {
            Schema::create('atlas_dev_run_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.run_certification.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->uuid('task_packet_id')->nullable()->index();
                $table->uuid('context_gate_id')->nullable()->index();
                $table->uuid('failure_capsule_id')->nullable()->index();
                $table->uuid('outcome_memory_id')->nullable()->index();
                $table->string('status', 40)->index();
                $table->json('summary');
                $table->json('checks');
                $table->json('blockers');
                $table->string('certification_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_dev_decision_materializations')) {
            Schema::create('atlas_dev_decision_materializations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.dev.decision_materialization.v1');
                $table->string('uuid', 64)->unique();
                $table->string('run_id', 120)->index();
                $table->string('task_id', 120)->index();
                $table->uuid('task_packet_id')->nullable()->index();
                $table->string('decision_kind', 120)->index();
                $table->string('status', 40)->index();
                $table->json('payload');
                $table->string('decision_hash', 64)->unique();
                $table->timestamps();

                $table->unique(['run_id', 'task_id', 'decision_kind'], 'uniq_atlas_dev_decision_run_task_kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_dev_decision_materializations');
        Schema::dropIfExists('atlas_dev_run_certifications');
        Schema::dropIfExists('atlas_dev_outcome_memories');
        Schema::dropIfExists('atlas_dev_failure_capsules');
        Schema::dropIfExists('atlas_dev_context_gates');
        Schema::dropIfExists('atlas_dev_task_packets');
    }
};
