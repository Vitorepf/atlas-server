<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesRouterRuntimeTables
{
    protected function createRouterRuntimeTables(): void
    {
        $this->dropRouterRuntimeTables();

        Schema::create('ai_atlas_intent_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.intent_classification.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->text('raw_input');
            $table->text('normalized_intent')->nullable();
            $table->string('intent_type', 60)->index();
            $table->decimal('ambiguity_score', 5, 4)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('signals');
            $table->string('status', 40)->default('classified')->index();
            $table->timestamps();
        });

        Schema::create('ai_atlas_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.router_decision.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->uuid('intent_classification_id')->nullable()->index();
            $table->string('primary_domain', 80)->index();
            $table->json('secondary_domains');
            $table->string('routing_mode', 40)->index();
            $table->json('decision_reason');
            $table->boolean('policy_required')->default(false)->index();
            $table->boolean('evidence_required')->default(false)->index();
            $table->boolean('tool_plan_required')->default(false)->index();
            $table->string('status', 40)->default('routed')->index();
            $table->string('receipt_hash', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_atlas_flow_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.flow_route.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('router_decision_id')->index();
            $table->string('flow_id', 80)->index();
            $table->string('flow_profile', 80)->nullable()->index();
            $table->string('runtime_mode', 40)->index();
            $table->json('expected_capabilities');
            $table->json('required_gates');
            $table->json('fallback_flows')->nullable();
            $table->string('status', 40)->default('ready')->index();
            $table->timestamps();
        });

        Schema::create('ai_atlas_runtime_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.runtime_dispatch.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('router_decision_id')->index();
            $table->uuid('flow_route_id')->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('dispatch_target', 120)->index();
            $table->string('dispatch_status', 40)->index();
            $table->json('dispatch_payload');
            $table->json('evidence_refs')->nullable();
            $table->json('blockers')->nullable();
            $table->string('receipt_hash', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_atlas_decision_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.decision_receipt.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->uuid('runtime_dispatch_id')->nullable()->index();
            $table->string('receipt_type', 60)->index();
            $table->json('decision_summary');
            $table->json('evidence_refs')->nullable();
            $table->json('policy_refs')->nullable();
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropRouterRuntimeTables(): void
    {
        foreach ([
            'ai_atlas_decision_receipts',
            'ai_atlas_runtime_dispatches',
            'ai_atlas_flow_routes',
            'ai_atlas_router_decisions',
            'ai_atlas_intent_classifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
