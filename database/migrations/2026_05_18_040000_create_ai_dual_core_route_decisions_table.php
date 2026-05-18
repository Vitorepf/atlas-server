<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_dual_core_route_decisions')) {
            return;
        }

        Schema::create('ai_dual_core_route_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.dual_core.route_decision.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->uuid('intent_classification_id')->nullable()->index();
            $table->string('conversation_id', 120)->nullable()->index();
            $table->string('route', 40)->index();
            $table->text('reason');
            $table->text('intent_summary');
            $table->string('ambiguity_level', 20)->default('medium')->index();
            $table->string('risk_level', 20)->default('medium')->index();
            $table->string('expected_duration', 20)->default('hours')->index();
            $table->unsignedInteger('modules_touched_estimate')->default(1);
            $table->boolean('sdd_required')->default(false)->index();
            $table->json('evidence_required');
            $table->boolean('operator_visible')->default(true)->index();
            $table->json('rejected_routes')->nullable();
            $table->json('routing_signals')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('policy_refs')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->string('actor_type', 40)->default('system')->index();
            $table->string('decision_hash', 64)->unique();
            $table->timestamps();

            $table->index(['route', 'created_at'], 'idx_dcrd_route_created');
            $table->index(['risk_level', 'route'], 'idx_dcrd_risk_route');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_dual_core_route_decisions');
    }
};
