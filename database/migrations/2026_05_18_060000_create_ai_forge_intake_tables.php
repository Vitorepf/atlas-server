<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            Schema::create('ai_forge_intakes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.forge.intake.v1');
                $table->string('uuid', 64)->unique();
                $table->string('origin', 40)->index();
                $table->string('recommended_forge_mode', 40)->index();
                $table->string('obra_title', 500);
                $table->string('workspace_slug', 200)->nullable()->index();
                $table->text('original_user_intent');
                $table->text('normalized_intent')->nullable();
                $table->text('scope_assessment')->nullable();
                $table->text('risk_assessment')->nullable();
                $table->text('ambiguity_assessment')->nullable();
                $table->string('risk_band', 20)->default('medium')->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->string('escalation_packet_id', 120)->nullable()->index();
                $table->string('escalation_packet_hash', 64)->nullable()->index();
                $table->text('promotion_reason')->nullable();
                $table->json('promotion_triggers')->nullable();
                $table->json('definition_of_done');
                $table->json('required_evidence');
                $table->json('evidence_refs')->nullable();
                $table->json('context_refs')->nullable();
                $table->string('context_pack_hash', 64)->nullable();
                $table->json('constraints')->nullable();
                $table->json('non_goals')->nullable();
                $table->json('current_dev_findings')->nullable();
                $table->json('completed_dev_actions')->nullable();
                $table->json('incomplete_dev_actions')->nullable();
                $table->string('status', 40)->default('ready')->index();
                $table->text('blocker_reason')->nullable();
                $table->string('intake_hash', 64)->unique();
                $table->string('actor_type', 40)->default('system')->index();
                $table->timestamps();

                $table->index(['origin', 'created_at'], 'idx_fi_origin_created');
                $table->index(['recommended_forge_mode', 'status'], 'idx_fi_mode_status');
            });
        }

        if (! Schema::hasTable('ai_forge_work_packets')) {
            Schema::create('ai_forge_work_packets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.forge.work_packet.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('intake_id')->index();
                $table->unsignedSmallInteger('packet_position')->index();
                $table->string('packet_id', 80)->index();
                $table->string('title', 500);
                $table->text('objective');
                $table->text('scope')->nullable();
                $table->json('expected_files')->nullable();
                $table->json('dependencies')->nullable();
                $table->json('risks')->nullable();
                $table->json('acceptance_criteria');
                $table->json('required_evidence');
                $table->json('suggested_tests')->nullable();
                $table->string('status', 40)->default('proposed')->index();
                $table->string('owner', 120)->nullable()->index();
                $table->string('role_slot', 80)->nullable()->index();
                $table->string('risk_band', 20)->default('medium')->index();
                $table->string('packet_hash', 64)->index();
                $table->timestamps();

                $table->unique(['intake_id', 'packet_id'], 'uniq_fwp_intake_packet');
                $table->index(['intake_id', 'packet_position'], 'idx_fwp_intake_pos');
            });
        }

        if (! Schema::hasTable('ai_forge_milestones')) {
            Schema::create('ai_forge_milestones', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.forge.milestone.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('intake_id')->index();
                $table->unsignedSmallInteger('position')->index();
                $table->string('milestone_id', 60)->index();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->json('expected_artifacts');
                $table->json('required_gates');
                $table->json('required_evidence');
                $table->string('status', 40)->default('pending')->index();
                $table->text('blocker_reason')->nullable();
                $table->string('milestone_hash', 64)->index();
                $table->timestamps();

                $table->unique(['intake_id', 'milestone_id'], 'uniq_fm_intake_milestone');
                $table->index(['intake_id', 'position'], 'idx_fm_intake_pos');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forge_milestones');
        Schema::dropIfExists('ai_forge_work_packets');
        Schema::dropIfExists('ai_forge_intakes');
    }
};
