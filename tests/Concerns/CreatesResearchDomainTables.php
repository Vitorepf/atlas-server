<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesResearchDomainTables
{
    protected function createResearchDomainTables(): void
    {
        $this->dropResearchDomainTables();

        Schema::create('ai_research_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.research_run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('question', 500);
            $table->text('hypothesis')->nullable();
            $table->json('source_plan');
            $table->string('status', 40)->default('planned')->index();
            $table->decimal('source_diversity', 5, 4)->nullable();
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->string('certification_status', 40)->nullable()->index();
            $table->json('missing_requirements')->nullable();
            $table->string('certification_hash', 64)->nullable()->index();
            $table->string('evidence_pack_hash', 64)->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_research_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.research_source.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('research_run_id')->index();
            $table->string('source_type', 60)->index();
            $table->string('source_ref', 500);
            $table->string('title', 500)->nullable();
            $table->string('author', 200)->nullable();
            $table->date('published_at')->nullable();
            $table->string('status', 40)->index();
            $table->decimal('source_quality', 5, 4)->nullable();
            $table->json('quality_factors')->nullable();
            $table->string('reason_rejected', 200)->nullable();
            $table->string('citation_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_research_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.research_claim.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('research_run_id')->index();
            $table->text('statement');
            $table->json('source_refs');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('claim_status', 40)->default('proposed')->index();
            $table->json('contradiction_refs')->nullable();
            $table->string('contradiction_status', 40)->default('none')->index();
            $table->string('claim_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_research_syntheses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.research_synthesis.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('research_run_id')->index();
            $table->text('brief');
            $table->json('claim_refs');
            $table->json('source_refs');
            $table->json('contradictions')->nullable();
            $table->json('open_questions')->nullable();
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('synthesis_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropResearchDomainTables(): void
    {
        foreach ([
            'ai_research_syntheses',
            'ai_research_claims',
            'ai_research_sources',
            'ai_research_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
