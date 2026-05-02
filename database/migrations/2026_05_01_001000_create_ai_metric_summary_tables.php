<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_cost_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->unsignedInteger('input_microusd_per_1k');
            $table->unsignedInteger('output_microusd_per_1k');
            $table->string('currency', 8)->default('USD');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'model', 'effective_from'], 'idx_ai_provider_cost_rates_lookup');
        });

        Schema::create('ai_outcome_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('outcome_type', 80);
            $table->string('target_type', 80)->nullable();
            $table->uuid('target_id')->nullable();
            $table->unsignedSmallInteger('value_score')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('source', 40)->default('system');
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trace_id', 'occurred_at'], 'idx_ai_outcome_trace');
            $table->index(['outcome_type', 'occurred_at'], 'idx_ai_outcome_type');
            $table->unique(['trace_id', 'outcome_type', 'target_type', 'target_id'], 'uniq_ai_outcome_trace_target');
        });

        Schema::create('ai_trace_metric_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->unique();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('client_id')->nullable()->index();
            $table->string('surface', 32);
            $table->string('runtime', 32)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('agent_slug', 120)->nullable();
            $table->string('task_type', 80)->nullable();
            $table->string('status', 32);
            $table->integer('app_send_to_accept_ms')->nullable();
            $table->integer('app_send_to_visible_ms')->nullable();
            $table->integer('queue_wait_ms')->nullable();
            $table->integer('first_token_ms')->nullable();
            $table->integer('provider_latency_ms')->nullable();
            $table->integer('total_latency_ms')->nullable();
            $table->boolean('backgrounded_during_run')->default(false);
            $table->boolean('recovered_from_pending')->default(false);
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->integer('estimated_tokens')->nullable();
            $table->string('token_source', 32)->nullable();
            $table->bigInteger('cost_microusd')->nullable();
            $table->string('cost_confidence', 32)->default('unknown');
            $table->string('cost_source', 48)->nullable();
            $table->string('cost_mode', 48)->default('unknown');
            $table->integer('context_tokens')->nullable();
            $table->integer('context_refs_count')->nullable();
            $table->integer('useful_context_refs_count')->nullable();
            $table->boolean('compaction_used')->default(false);
            $table->boolean('provider_handoff_used')->default(false);
            $table->unsignedSmallInteger('context_efficiency_score')->nullable();
            $table->unsignedSmallInteger('auto_quality_score')->nullable();
            $table->unsignedSmallInteger('continuity_score')->nullable();
            $table->unsignedSmallInteger('human_feedback_score')->nullable();
            $table->unsignedSmallInteger('outcome_score')->nullable();
            $table->unsignedSmallInteger('remediation_score')->nullable();
            $table->unsignedSmallInteger('final_quality_score')->nullable();
            $table->unsignedSmallInteger('final_efficiency_score')->nullable();
            $table->boolean('first_pass_success')->nullable();
            $table->boolean('needed_remediation')->default(false);
            $table->integer('remediation_count')->default(0);
            $table->boolean('reask_detected')->default(false);
            $table->boolean('provider_switched_after_response')->default(false);
            $table->json('score_components')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->index(['surface', 'computed_at'], 'idx_ai_trace_metric_surface_computed');
            $table->index(['provider', 'computed_at'], 'idx_ai_trace_metric_provider_computed');
            $table->index(['final_quality_score', 'computed_at'], 'idx_ai_trace_metric_quality');
            $table->index(['final_efficiency_score', 'computed_at'], 'idx_ai_trace_metric_efficiency');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_trace_metric_summaries_updated_at
                BEFORE UPDATE ON ai_trace_metric_summaries
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_trace_metric_summaries');
        Schema::dropIfExists('ai_outcome_links');
        Schema::dropIfExists('ai_provider_cost_rates');
    }
};
