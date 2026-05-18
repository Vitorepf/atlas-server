<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesLocalAgentIngestionTables
{
    protected function createLocalAgentIngestionTables(): void
    {
        $this->dropLocalAgentIngestionTables();

        Schema::create('ai_local_agent_ingestion_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 64)->unique();
            $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.run.v1');
            $table->string('status', 40)->index();
            $table->boolean('dry_run')->default(false);
            $table->string('actor_alias', 120);
            $table->json('root_aliases');
            $table->string('root_alias_fingerprint', 64);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->json('config_snapshot');
            $table->json('summary');
            $table->string('receipt_hash', 64);
            $table->timestamps();
        });

        Schema::create('ai_local_agent_ingestion_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 64)->unique();
            $table->uuid('run_id');
            $table->string('run_uuid', 64);
            $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.source.v1');
            $table->string('alias', 120);
            $table->string('relative_path', 1024);
            $table->string('extension', 40)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestampTz('mtime')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->text('redacted_snippet')->nullable();
            $table->string('source_class', 60);
            $table->json('classification_signals');
            $table->unsignedSmallInteger('quality_score')->default(0);
            $table->unsignedSmallInteger('freshness_score')->default(0);
            $table->unsignedSmallInteger('secret_finding_count')->default(0);
            $table->json('secret_finding_counts');
            $table->string('status', 40);
            $table->string('skip_reason', 60)->nullable();
            $table->string('source_hash', 64);
            $table->timestamps();
        });

        Schema::create('ai_local_agent_ingestion_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 64)->unique();
            $table->uuid('run_id');
            $table->string('run_uuid', 64);
            $table->uuid('source_id')->nullable();
            $table->string('source_uuid', 64)->nullable();
            $table->string('schema_version', 120)->default('atlas.ai.local_agent_ingestion.candidate.v1');
            $table->string('source_class', 60);
            $table->string('status', 40);
            $table->boolean('memory_eligible')->default(false);
            $table->boolean('context_eligible')->default(false);
            $table->boolean('embedding_allowed')->default(false);
            $table->string('promotion_target', 60)->nullable();
            $table->json('evidence_refs');
            $table->json('payload');
            $table->string('candidate_hash', 64);
            $table->timestamps();
        });
    }

    protected function dropLocalAgentIngestionTables(): void
    {
        foreach ([
            'ai_local_agent_ingestion_candidates',
            'ai_local_agent_ingestion_sources',
            'ai_local_agent_ingestion_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
