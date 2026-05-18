<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_missions')) {
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

                $table->index(['status', 'created_at'], 'idx_ai_missions_status_created');
                $table->index(['mission_type', 'status'], 'idx_ai_missions_type_status');
            });
        }

        if (! Schema::hasTable('ai_objectives')) {
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

                $table->index(['mission_id', 'status'], 'idx_ai_objectives_mission_status');
                $table->index(['mission_id', 'priority'], 'idx_ai_objectives_mission_priority');
            });
        }

        if (! Schema::hasTable('ai_work_orders')) {
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

                $table->index(['mission_id', 'status'], 'idx_ai_work_orders_mission_status');
                $table->index(['receipt_hash'], 'idx_ai_work_orders_receipt_hash');
            });
        }

        if (! Schema::hasTable('ai_mission_events')) {
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

                $table->index(['mission_id', 'created_at'], 'idx_ai_mission_events_mission_created');
                $table->index(['event_type', 'created_at'], 'idx_ai_mission_events_type_created');
            });
        }

        if (! Schema::hasTable('ai_mission_evidence_refs')) {
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

                $table->index(['mission_id', 'evidence_type'], 'idx_ai_mission_evidence_mission_type');
            });
        }

        if (! Schema::hasTable('ai_mission_certifications')) {
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

                $table->index(['mission_id', 'status'], 'idx_ai_mission_certifications_mission_status');
                $table->index(['mission_id', 'created_at'], 'idx_ai_mission_certifications_mission_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mission_certifications');
        Schema::dropIfExists('ai_mission_evidence_refs');
        Schema::dropIfExists('ai_mission_events');
        Schema::dropIfExists('ai_work_orders');
        Schema::dropIfExists('ai_objectives');
        Schema::dropIfExists('ai_missions');
    }
};
