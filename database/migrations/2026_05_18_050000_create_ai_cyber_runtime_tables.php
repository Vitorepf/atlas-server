<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_cyber_engagements')) {
            Schema::create('ai_cyber_engagements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.engagement.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('engagement_id', 120)->index();
                $table->string('engagement_kind', 60)->index();
                $table->string('title', 500);
                $table->text('summary')->nullable();
                $table->string('requester', 200);
                $table->json('targets');
                $table->boolean('authorization_present')->default(false)->index();
                $table->json('authorization')->nullable();
                $table->json('legal_review')->nullable();
                $table->json('privacy_review')->nullable();
                $table->string('status', 40)->default('intake')->index();
                $table->json('blockers')->nullable();
                $table->string('next_action', 200)->nullable();
                $table->string('engagement_hash', 64)->unique();
                $table->timestamps();

                $table->index(['engagement_kind', 'status'], 'idx_ai_cyber_engagements_kind_status');
            });
        }

        if (! Schema::hasTable('ai_cyber_scope_rules')) {
            Schema::create('ai_cyber_scope_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.scope_rules.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->index();
                $table->string('scope_id', 120)->index();
                $table->json('in_scope_targets');
                $table->json('out_of_scope_targets');
                $table->json('allowed_techniques');
                $table->json('forbidden_techniques');
                $table->json('rate_limits')->nullable();
                $table->json('time_windows')->nullable();
                $table->json('legal_constraints')->nullable();
                $table->json('privacy_constraints')->nullable();
                $table->json('escalation_contacts');
                $table->string('status', 40)->default('draft')->index();
                $table->string('rules_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_appsec_reviews')) {
            Schema::create('ai_appsec_reviews', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.appsec_review.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->nullable()->index();
                $table->string('review_id', 120)->index();
                $table->string('title', 500);
                $table->string('target_kind', 60)->index();
                $table->string('target_ref', 500);
                $table->json('owasp_categories');
                $table->json('findings');
                $table->json('recommendations');
                $table->json('artifact_refs')->nullable();
                $table->decimal('risk_score', 5, 2)->nullable();
                $table->string('status', 40)->default('open')->index();
                $table->string('review_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_grc_mappings')) {
            Schema::create('ai_grc_mappings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.grc_mapping.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->nullable()->index();
                $table->string('mapping_id', 120)->index();
                $table->string('framework', 60)->index();
                $table->string('control_id', 120)->index();
                $table->string('control_title', 500);
                $table->json('evidence_refs')->nullable();
                $table->json('gaps')->nullable();
                $table->string('compliance_status', 40)->default('unassessed')->index();
                $table->json('remediation_refs')->nullable();
                $table->string('mapping_hash', 64)->unique();
                $table->timestamps();

                $table->index(['framework', 'compliance_status'], 'idx_ai_grc_mappings_framework_status');
            });
        }

        if (! Schema::hasTable('ai_remediation_plans')) {
            Schema::create('ai_remediation_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.remediation_plan.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->nullable()->index();
                $table->uuid('appsec_review_id')->nullable()->index();
                $table->string('plan_id', 120)->index();
                $table->string('title', 500);
                $table->string('severity', 40)->default('medium')->index();
                $table->json('findings_refs');
                $table->json('actions');
                $table->json('owners');
                $table->json('timeline');
                $table->json('rollback_plan')->nullable();
                $table->string('status', 40)->default('proposed')->index();
                $table->string('plan_hash', 64)->unique();
                $table->timestamps();

                $table->index(['severity', 'status'], 'idx_ai_remediation_plans_severity_status');
            });
        }

        if (! Schema::hasTable('ai_defensive_security_reviews')) {
            Schema::create('ai_defensive_security_reviews', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.defensive_review.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->nullable()->index();
                $table->string('review_id', 120)->index();
                $table->string('review_kind', 60)->index();
                $table->string('title', 500);
                $table->json('scope');
                $table->json('controls_inspected');
                $table->json('findings');
                $table->json('recommendations');
                $table->json('artifact_refs')->nullable();
                $table->string('status', 40)->default('open')->index();
                $table->string('review_hash', 64)->unique();
                $table->timestamps();

                $table->index(['review_kind', 'status'], 'idx_ai_defensive_reviews_kind_status');
            });
        }

        if (! Schema::hasTable('ai_bug_bounty_intakes')) {
            Schema::create('ai_bug_bounty_intakes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.bug_bounty_intake.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->index();
                $table->string('intake_id', 120)->index();
                $table->string('program', 200);
                $table->string('program_url', 500)->nullable();
                $table->boolean('authorization_present')->default(false)->index();
                $table->json('authorization_doc')->nullable();
                $table->boolean('scope_parsed')->default(false);
                $table->boolean('roe_documented')->default(false);
                $table->boolean('legal_gate_passed')->default(false);
                $table->boolean('privacy_gate_passed')->default(false);
                $table->json('handoff_plan')->nullable();
                $table->string('status', 40)->default('blocked')->index();
                $table->json('blockers')->nullable();
                $table->string('intake_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_cyber_evidence_chain')) {
            Schema::create('ai_cyber_evidence_chain', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.cyber.evidence_chain.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('engagement_id')->index();
                $table->uuid('parent_entry_id')->nullable()->index();
                $table->string('entry_kind', 60)->index();
                $table->string('actor', 200);
                $table->json('payload');
                $table->json('artifact_refs')->nullable();
                $table->string('previous_hash', 64)->nullable();
                $table->string('entry_hash', 64)->unique();
                $table->timestamps();

                $table->index(['engagement_id', 'created_at'], 'idx_ai_cyber_evidence_chain_engagement_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cyber_evidence_chain');
        Schema::dropIfExists('ai_bug_bounty_intakes');
        Schema::dropIfExists('ai_defensive_security_reviews');
        Schema::dropIfExists('ai_remediation_plans');
        Schema::dropIfExists('ai_grc_mappings');
        Schema::dropIfExists('ai_appsec_reviews');
        Schema::dropIfExists('ai_cyber_scope_rules');
        Schema::dropIfExists('ai_cyber_engagements');
    }
};
