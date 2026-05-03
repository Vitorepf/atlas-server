<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasEngineeringKnowledgeTables
{
    protected function createAtlasEngineeringKnowledgeTables(): void
    {
        $this->dropAtlasEngineeringKnowledgeTables();

        Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('title', 220);
            $table->string('category', 80)->index();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedSmallInteger('priority')->default(50)->index();
            $table->string('source_type', 80)->default('canonical_doc')->index();
            $table->text('canonical_path');
            $table->string('source_hash', 64)->index();
            $table->string('content_hash', 64)->index();
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('tags_json')->default('[]');
            $table->json('related_paths_json')->default('[]');
            $table->json('capabilities_json')->default('[]');
            $table->json('decisions_json')->default('[]');
            $table->json('maintenance_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function dropAtlasEngineeringKnowledgeTables(): void
    {
        Schema::dropIfExists('atlas_engineering_knowledge_items');
    }
}
