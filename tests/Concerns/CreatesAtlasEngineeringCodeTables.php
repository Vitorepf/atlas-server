<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasEngineeringCodeTables
{
    protected function createAtlasEngineeringCodeTables(): void
    {
        $this->dropAtlasEngineeringCodeTables();

        Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('name', 220);
            $table->string('layer', 80)->index();
            $table->string('root_path', 500)->nullable()->index();
            $table->string('primary_language', 40)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('owner', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('symbol_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->unsignedInteger('command_count')->default(0);
            $table->unsignedInteger('migration_count')->default(0);
            $table->unsignedInteger('test_count')->default(0);
            $table->string('source_hash', 64)->index();
            $table->string('docs_hash', 64)->nullable()->index();
            $table->json('tags_json')->default('[]');
            $table->json('related_docs_json')->default('[]');
            $table->json('related_tests_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('namespace', 220)->nullable();
            $table->string('parent_symbol', 300)->nullable()->index();
            $table->string('visibility', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->string('source_hash', 64)->index();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('knowledge_item_id')->nullable()->index();
            $table->uuid('module_id')->nullable()->index();
            $table->uuid('symbol_id')->nullable()->index();
            $table->string('link_type', 60)->index();
            $table->string('status', 40)->default('current')->index();
            $table->string('canonical_path', 500)->index();
            $table->string('target_path', 500)->nullable()->index();
            $table->string('doc_hash', 64)->nullable()->index();
            $table->string('target_hash', 64)->nullable()->index();
            $table->string('link_hash', 64)->unique();
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function dropAtlasEngineeringCodeTables(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
    }
}
