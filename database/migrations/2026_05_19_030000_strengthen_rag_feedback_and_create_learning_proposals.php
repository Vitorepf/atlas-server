<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_rag_feedback_events')) {
            Schema::table('ai_rag_feedback_events', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_rag_feedback_events', 'outcome_status')) {
                    $table->string('outcome_status', 40)->nullable()->index();
                }
                if (! Schema::hasColumn('ai_rag_feedback_events', 'failure_reason')) {
                    $table->string('failure_reason', 160)->nullable();
                }
                if (! Schema::hasColumn('ai_rag_feedback_events', 'next_retrieval_hint')) {
                    $table->json('next_retrieval_hint')->nullable();
                }
                if (! Schema::hasColumn('ai_rag_feedback_events', 'memory_candidate_id')) {
                    $table->uuid('memory_candidate_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ai_rag_feedback_events', 'learning_proposal_id')) {
                    $table->uuid('learning_proposal_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ai_rag_feedback_events', 'run_outcome_id')) {
                    $table->uuid('run_outcome_id')->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('ai_learning_proposals')) {
            Schema::create('ai_learning_proposals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 80)->default('atlas.ai.compounding.learning_proposal.v1');
                $table->string('kind', 40)->index();
                // proposal-only — never auto-applied per atlas-compounding-engineering-intelligence.md
                $table->string('status', 32)->default('proposed')->index();
                $table->string('scope', 120)->default('global')->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->text('summary');
                $table->json('current_state')->nullable();
                $table->json('proposed_state')->nullable();
                $table->json('evidence_refs');
                $table->uuid('run_outcome_id')->nullable()->index();
                $table->uuid('learning_candidate_id')->nullable()->index();
                $table->uuid('rag_feedback_id')->nullable()->index();
                $table->boolean('requires_human_review')->default(true);
                $table->string('decided_by', 120)->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_notes')->nullable();
                $table->json('payload')->nullable();
                $table->string('proposal_hash', 64)->unique();
                $table->timestamps();

                $table->index(['kind', 'status']);
                $table->index(['scope', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learning_proposals');

        if (Schema::hasTable('ai_rag_feedback_events')) {
            Schema::table('ai_rag_feedback_events', function (Blueprint $table): void {
                foreach ([
                    'outcome_status',
                    'failure_reason',
                    'next_retrieval_hint',
                    'memory_candidate_id',
                    'learning_proposal_id',
                    'run_outcome_id',
                ] as $column) {
                    if (Schema::hasColumn('ai_rag_feedback_events', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
