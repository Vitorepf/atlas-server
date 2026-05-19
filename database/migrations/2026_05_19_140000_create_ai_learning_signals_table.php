<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_learning_signals')) {
            return;
        }

        Schema::create('ai_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.ai.learning_signal.v1');
            $table->string('signal_id', 80)->unique();
            // Source identifies what kind of event produced the signal. Canonical
            // values populated by AtlasAiLearningLoopService::SOURCE_*. Open-ended
            // string so new collectors can extend without migration churn.
            $table->string('source_type', 60)->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->string('flow_id', 80)->nullable()->index();
            // Outcome is the verdict: succeeded/failed/blocked/approved/denied/unknown.
            $table->string('outcome', 32)->nullable()->index();
            $table->smallInteger('quality_score')->nullable();
            $table->string('failure_mode', 160)->nullable();
            $table->string('blocker_reason', 200)->nullable();
            $table->string('approval_decision', 32)->nullable();
            $table->json('evidence_refs');
            $table->json('proposed_memory_delta')->nullable();
            $table->json('proposed_policy_delta')->nullable();
            $table->json('proposed_rag_feedback')->nullable();
            $table->boolean('requires_review')->default(true);
            // risk_level: 'low' (harmless preference) | 'critical' (policy/routing/
            // security/finance/programming). Hard rule per Atlas Compounding:
            // critical NEVER auto-applies; low may fast-track operator review.
            $table->string('risk_level', 16)->default('critical')->index();
            // status: collected (default), proposed (AiLearningProposal exists),
            // dismissed (no actionable pattern), expired (stale).
            $table->string('status', 24)->default('collected')->index();
            $table->uuid('learning_proposal_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('signal_hash', 64)->unique();
            $table->timestamp('collected_at')->nullable()->index();
            $table->timestamps();

            $table->index(['source_type', 'status']);
            $table->index(['risk_level', 'status']);
            $table->index(['mission_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learning_signals');
    }
};
