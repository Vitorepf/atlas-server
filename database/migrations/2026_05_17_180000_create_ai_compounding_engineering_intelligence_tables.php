<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_run_outcomes')) {
            Schema::create('ai_run_outcomes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.outcome.v1');
                $table->string('run_id', 120)->index();
                $table->uuid('trace_id')->nullable()->index();
                $table->string('flow_id', 80)->index();
                $table->string('outcome_status', 40)->index();
                $table->unsignedTinyInteger('flow_quality')->default(0);
                $table->unsignedTinyInteger('retrieval_quality')->default(0);
                $table->unsignedTinyInteger('execution_quality')->default(0);
                $table->unsignedTinyInteger('evidence_quality')->default(0);
                $table->boolean('human_override')->default(false)->index();
                $table->boolean('learning_required')->default(true)->index();
                $table->json('missed_signals')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('payload')->nullable();
                $table->string('outcome_hash', 64)->unique();
                $table->timestamp('evaluated_at')->nullable()->index();
                $table->timestamps();

                $table->index(['flow_id', 'outcome_status', 'created_at']);
            });
        }

        if (! Schema::hasTable('ai_learning_candidates')) {
            Schema::create('ai_learning_candidates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.learning_candidate.v1');
                $table->uuid('run_outcome_id')->nullable()->index();
                $table->string('candidate_hash', 64)->unique();
                $table->string('status', 40)->index();
                $table->string('decision', 40)->index();
                $table->string('memory_type', 80)->nullable()->index();
                $table->string('scope', 120)->default('global')->index();
                $table->text('claim')->nullable();
                $table->unsignedTinyInteger('confidence')->default(0)->index();
                $table->boolean('promotion_allowed')->default(false)->index();
                $table->json('evidence_refs')->nullable();
                $table->json('payload')->nullable();
                $table->string('receipt_hash', 64)->index();
                $table->timestamp('decided_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_compounding_memories')) {
            Schema::create('ai_compounding_memories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.memory.v1');
                $table->uuid('learning_candidate_id')->nullable()->index();
                $table->string('memory_type', 80)->index();
                $table->string('scope', 120)->default('global')->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->string('status', 40)->default('active')->index();
                $table->text('claim');
                $table->unsignedTinyInteger('confidence')->default(0)->index();
                $table->json('evidence_refs');
                $table->string('revalidation_policy', 160);
                $table->timestamp('valid_until')->nullable()->index();
                $table->timestamp('last_revalidated_at')->nullable()->index();
                $table->json('payload')->nullable();
                $table->string('memory_hash', 64)->unique();
                $table->timestamps();

                $table->index(['memory_type', 'scope', 'status']);
                $table->index(['flow_id', 'status', 'confidence']);
            });
        }

        if (! Schema::hasTable('ai_heuristic_updates')) {
            Schema::create('ai_heuristic_updates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.heuristic_update.v1');
                $table->string('heuristic_key', 120)->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->string('status', 40)->default('proposed')->index();
                $table->json('before_state');
                $table->json('after_state');
                $table->json('evidence_refs');
                $table->json('rollback_plan');
                $table->json('test_refs');
                $table->string('receipt_hash', 64)->unique();
                $table->timestamp('applied_at')->nullable()->index();
                $table->timestamp('rolled_back_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_rag_feedback_events')) {
            Schema::create('ai_rag_feedback_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.rag.feedback.v1');
                $table->string('retrieval_receipt_id', 120)->index();
                $table->string('flow_id', 80)->index();
                $table->string('query_plan_hash', 64)->nullable()->index();
                $table->unsignedInteger('included_sources')->default(0);
                $table->unsignedInteger('used_sources')->default(0);
                $table->unsignedInteger('noise_sources')->default(0);
                $table->json('missed_required_sources')->nullable();
                $table->unsignedTinyInteger('context_sufficiency')->default(0);
                $table->unsignedTinyInteger('post_execution_utility')->default(0);
                $table->json('source_utility')->nullable();
                $table->json('payload')->nullable();
                $table->string('feedback_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_benchmark_cases')) {
            Schema::create('ai_benchmark_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.benchmark_case.v1');
                $table->uuid('run_outcome_id')->nullable()->index();
                $table->string('case_id', 120)->unique();
                $table->string('source', 80)->index();
                $table->text('prompt')->nullable();
                $table->string('expected_flow', 80)->nullable()->index();
                $table->json('required_evidence')->nullable();
                $table->json('rivals')->nullable();
                $table->string('status', 40)->default('active')->index();
                $table->json('payload')->nullable();
                $table->string('case_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_temporal_certifications')) {
            Schema::create('ai_temporal_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.temporal_certification.v1');
                $table->string('status', 40)->index();
                $table->timestamp('window_started_at')->nullable()->index();
                $table->timestamp('window_ended_at')->nullable()->index();
                $table->json('flow_deltas')->nullable();
                $table->json('rival_deltas')->nullable();
                $table->json('claim_policy')->nullable();
                $table->json('blockers')->nullable();
                $table->string('certification_hash', 64)->unique();
                $table->timestamp('certified_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_temporal_certifications');
        Schema::dropIfExists('ai_benchmark_cases');
        Schema::dropIfExists('ai_rag_feedback_events');
        Schema::dropIfExists('ai_heuristic_updates');
        Schema::dropIfExists('ai_compounding_memories');
        Schema::dropIfExists('ai_learning_candidates');
        Schema::dropIfExists('ai_run_outcomes');
    }
};
