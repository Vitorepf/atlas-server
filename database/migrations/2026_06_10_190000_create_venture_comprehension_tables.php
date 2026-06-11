<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_venture_comprehension_runs')) {
            Schema::create('ai_venture_comprehension_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.comprehension_run.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                $table->string('workspace_path', 1000);
                $table->json('repo_roots')->nullable();
                $table->string('status', 40)->default('running')->index();
                $table->json('capabilities_run')->nullable();
                $table->json('summary')->nullable();
                $table->unsignedInteger('files_scanned')->default(0);
                $table->unsignedInteger('findings_total')->default(0);
                $table->boolean('analyzed')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('run_hash', 64)->unique();
                $table->timestamps();

                $table->index(['venture_id', 'created_at'], 'idx_venture_comprehension_runs_lookup');
            });
        }

        if (! Schema::hasTable('ai_venture_comprehension_findings')) {
            Schema::create('ai_venture_comprehension_findings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.comprehension_finding.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('run_id')->index();
                $table->uuid('venture_id')->index();
                // business_rule | problem | improvement | audience_usage
                $table->string('capability', 40)->index();
                $table->string('kind', 60)->index();
                $table->string('category', 80)->nullable()->index();
                $table->string('title', 500);
                $table->text('detail')->nullable();
                // problem severity: info|low|medium|high|critical
                $table->string('severity', 20)->nullable()->index();
                $table->decimal('impact_score', 5, 2)->nullable();
                $table->decimal('effort_score', 5, 2)->nullable();
                $table->decimal('leverage_score', 6, 3)->nullable()->index();
                $table->decimal('confidence', 4, 3)->nullable();
                // observed (cited from code) | inferred (LLM/heuristic)
                $table->string('evidence_kind', 20)->default('observed')->index();
                $table->string('evidence_path', 1000)->nullable();
                $table->unsignedInteger('evidence_line')->nullable();
                $table->text('evidence_snippet')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->text('recommendation')->nullable();
                $table->json('payload')->nullable();
                $table->string('source', 40)->default('deterministic')->index();
                $table->string('finding_hash', 64)->unique();
                $table->timestamps();

                $table->index(['run_id', 'capability'], 'idx_venture_findings_run_capability');
                $table->index(['venture_id', 'capability', 'severity'], 'idx_venture_findings_triage');
            });
        }

        if (! Schema::hasTable('ai_venture_documentation_artifacts')) {
            Schema::create('ai_venture_documentation_artifacts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.documentation_artifact.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('run_id')->nullable()->index();
                $table->uuid('venture_id')->index();
                $table->string('doc_kind', 80)->index();
                $table->string('title', 500);
                $table->string('relative_path', 1000);
                $table->boolean('written_to_disk')->default(false);
                $table->json('sections')->nullable();
                $table->longText('content')->nullable();
                $table->unsignedInteger('line_count')->default(0);
                $table->string('content_hash', 64)->index();
                $table->string('status', 40)->default('generated')->index();
                $table->timestamps();

                $table->index(['venture_id', 'doc_kind'], 'idx_venture_doc_artifacts_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_venture_documentation_artifacts');
        Schema::dropIfExists('ai_venture_comprehension_findings');
        Schema::dropIfExists('ai_venture_comprehension_runs');
    }
};
