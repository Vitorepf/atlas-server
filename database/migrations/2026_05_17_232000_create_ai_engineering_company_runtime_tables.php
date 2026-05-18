<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_engineering_company_engagements')) {
            Schema::create('ai_engineering_company_engagements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.engagement.v1');
                $table->string('engagement_id', 140)->unique();
                $table->longText('goal');
                $table->string('status', 40)->index();
                $table->string('target_runtime', 80)->default('atlas_real_execution_kernel')->index();
                $table->json('roles')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_cycles')) {
            Schema::create('ai_engineering_company_cycles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.cycle.v1');
                $table->string('cycle_id', 140)->unique();
                $table->unsignedInteger('cycle_index')->default(1)->index();
                $table->string('status', 40)->index();
                $table->json('plan')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('cycle_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_role_runs')) {
            Schema::create('ai_engineering_company_role_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->uuid('cycle_record_id')->nullable()->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.role_run.v1');
                $table->string('role_run_id', 140)->unique();
                $table->string('role_id', 80)->index();
                $table->string('status', 40)->index();
                $table->json('responsibilities')->nullable();
                $table->json('output')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('role_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_reviews')) {
            Schema::create('ai_engineering_company_reviews', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.review.v1');
                $table->string('review_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('findings')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('review_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_qa_runs')) {
            Schema::create('ai_engineering_company_qa_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.qa_run.v1');
                $table->string('qa_run_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('gates')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('qa_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_release_packs')) {
            Schema::create('ai_engineering_company_release_packs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.release_pack.v1');
                $table->string('release_pack_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('summary')->nullable();
                $table->json('risk_register')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('release_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_benchmarks')) {
            Schema::create('ai_engineering_company_benchmarks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.benchmark.v1');
                $table->string('benchmark_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('protocol')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('benchmark_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_company_certifications')) {
            Schema::create('ai_engineering_company_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('engagement_record_id')->nullable()->index();
                $table->string('schema_version', 160)->default('atlas.ai.engineering_company.certification.v1');
                $table->string('certification_id', 140)->unique();
                $table->string('status', 40)->index();
                $table->json('checks')->nullable();
                $table->json('blockers')->nullable();
                $table->json('claim_policy')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('certification_hash', 64)->unique();
                $table->timestamp('certified_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_engineering_company_certifications');
        Schema::dropIfExists('ai_engineering_company_benchmarks');
        Schema::dropIfExists('ai_engineering_company_release_packs');
        Schema::dropIfExists('ai_engineering_company_qa_runs');
        Schema::dropIfExists('ai_engineering_company_reviews');
        Schema::dropIfExists('ai_engineering_company_role_runs');
        Schema::dropIfExists('ai_engineering_company_cycles');
        Schema::dropIfExists('ai_engineering_company_engagements');
    }
};
