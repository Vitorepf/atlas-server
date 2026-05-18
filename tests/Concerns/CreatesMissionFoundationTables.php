<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesMissionFoundationTables
{
    protected function createMissionFoundationTables(): void
    {
        $this->dropMissionFoundationTables();

        Schema::create('ai_missions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.mission.v1');
            $table->string('uuid', 64)->unique();
            $table->string('title', 500);
            $table->text('raw_prompt');
            $table->text('normalized_intent')->nullable();
            $table->string('mission_type', 40)->index();
            $table->string('status', 40)->index();
            $table->string('autonomy_level', 40)->default('suggest')->index();
            $table->string('risk_level', 40)->default('low')->index();
            $table->json('definition_of_done');
            $table->text('context_summary')->nullable();
            $table->string('primary_domain', 80)->nullable()->index();
            $table->json('secondary_domains')->nullable();
            $table->string('current_step', 160)->nullable();
            $table->text('blocker_reason')->nullable();
            $table->string('evidence_pack_hash', 64)->nullable()->index();
            $table->string('certification_hash', 64)->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.objective.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->index();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('objective_type', 60)->default('deliverable')->index();
            $table->unsignedInteger('priority')->default(1)->index();
            $table->json('success_criteria');
            $table->json('constraints')->nullable();
            $table->json('assumptions')->nullable();
            $table->string('status', 40)->default('proposed')->index();
            $table->json('evidence_refs')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.work_order.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->index();
            $table->uuid('objective_id')->nullable()->index();
            $table->string('domain_runtime', 80)->nullable()->index();
            $table->string('flow_profile', 80)->nullable()->index();
            $table->string('title', 500);
            $table->text('instructions');
            $table->json('expected_artifacts');
            $table->json('expected_tests');
            $table->json('risk_notes');
            $table->json('rollback_plan')->nullable();
            $table->string('status', 40)->default('ready')->index();
            $table->json('evidence_refs')->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_mission_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.mission_event.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->index();
            $table->string('event_type', 80)->index();
            $table->string('status_before', 40)->nullable();
            $table->string('status_after', 40)->nullable();
            $table->string('actor_type', 40)->default('system')->index();
            $table->json('payload');
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_mission_evidence_refs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.evidence_ref.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('evidence_type', 40)->index();
            $table->text('evidence_ref');
            $table->string('evidence_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_mission_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.certification.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->index();
            $table->string('status', 40)->index();
            $table->json('checked_requirements');
            $table->json('missing_requirements');
            $table->json('evidence_refs');
            $table->string('certification_hash', 64)->unique();
            $table->timestamp('certified_at')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function dropMissionFoundationTables(): void
    {
        foreach ([
            'ai_mission_certifications',
            'ai_mission_evidence_refs',
            'ai_mission_events',
            'ai_work_orders',
            'ai_objectives',
            'ai_missions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
