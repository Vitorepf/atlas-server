<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_local_agent_ingestion_runs')) {
            Schema::create('ai_local_agent_ingestion_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('uuid', 64)->unique();
                $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.run.v1');
                $table->string('status', 40)->index();
                $table->boolean('dry_run')->default(false)->index();
                $table->string('actor_alias', 120)->index();
                $table->json('root_aliases');
                $table->string('root_alias_fingerprint', 64)->index();
                $table->timestampTz('started_at');
                $table->timestampTz('ended_at')->nullable();
                $table->json('config_snapshot');
                $table->json('summary');
                $table->string('receipt_hash', 64)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_local_agent_ingestion_sources')) {
            Schema::create('ai_local_agent_ingestion_sources', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('uuid', 64)->unique();
                $table->uuid('run_id')->index();
                $table->string('run_uuid', 64)->index();
                $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.source.v1');
                $table->string('alias', 120)->index();
                $table->string('relative_path', 1024);
                $table->string('extension', 40)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->timestampTz('mtime')->nullable();
                $table->string('content_hash', 64)->nullable()->index();
                $table->text('redacted_snippet')->nullable();
                $table->string('source_class', 60)->index();
                $table->json('classification_signals');
                $table->unsignedSmallInteger('quality_score')->default(0);
                $table->unsignedSmallInteger('freshness_score')->default(0);
                $table->unsignedSmallInteger('secret_finding_count')->default(0)->index();
                $table->json('secret_finding_counts');
                $table->string('status', 40)->index();
                $table->string('skip_reason', 60)->nullable()->index();
                $table->string('source_hash', 64)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_local_agent_ingestion_candidates')) {
            Schema::create('ai_local_agent_ingestion_candidates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('uuid', 64)->unique();
                $table->uuid('run_id')->index();
                $table->string('run_uuid', 64)->index();
                $table->uuid('source_id')->nullable()->index();
                $table->string('source_uuid', 64)->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.candidate.v1');
                $table->string('source_class', 60)->index();
                $table->string('status', 40)->index();
                $table->boolean('memory_eligible')->default(false);
                $table->boolean('context_eligible')->default(false);
                $table->boolean('embedding_allowed')->default(false);
                $table->string('promotion_target', 60)->nullable();
                $table->json('evidence_refs');
                $table->json('payload');
                $table->string('candidate_hash', 64)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_local_agent_ingestion_candidates');
        Schema::dropIfExists('ai_local_agent_ingestion_sources');
        Schema::dropIfExists('ai_local_agent_ingestion_runs');
    }
};
