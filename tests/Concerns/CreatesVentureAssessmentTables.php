<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesVentureAssessmentTables
{
    protected function createVentureAssessmentTables(): void
    {
        $this->dropVentureAssessmentTables();

        Schema::create('ai_venture_assessment_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.assessment_run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('venture_id')->index();
            $table->uuid('comprehension_run_id')->nullable()->index();
            $table->string('stage', 12)->default('S0')->index();
            $table->string('status', 40)->default('running')->index();
            $table->unsignedInteger('questions_total')->default(0);
            $table->unsignedInteger('answered_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('blocked_internal_count')->default(0);
            $table->unsignedInteger('blocked_external_count')->default(0);
            $table->decimal('data_readiness_pct', 5, 2)->default(0);
            $table->json('focus')->nullable();
            $table->json('data_gaps')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('run_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_venture_question_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.question_answer.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('run_id')->index();
            $table->uuid('venture_id')->index();
            $table->string('question_id', 80)->index();
            $table->string('dimension', 60)->index();
            $table->text('question_text');
            $table->string('status', 40)->index();
            $table->text('answer')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('evidence_kind', 20)->default('none');
            $table->json('evidence_refs')->nullable();
            $table->string('data_kind', 30)->index();
            $table->string('external_source', 60)->nullable()->index();
            $table->text('recommendation')->nullable();
            $table->decimal('priority_score', 7, 3)->default(0)->index();
            $table->string('severity_if_blind', 20)->nullable();
            $table->string('answer_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropVentureAssessmentTables(): void
    {
        Schema::dropIfExists('ai_venture_question_answers');
        Schema::dropIfExists('ai_venture_assessment_runs');
    }
}
