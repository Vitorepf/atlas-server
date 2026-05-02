<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_attachment_index_entries')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
            DB::statement(<<<'SQL'
                CREATE TABLE ai_attachment_index_entries (
                  id UUID PRIMARY KEY,
                  trace_id UUID NULL,
                  ai_job_id UUID NULL,
                  thread_id UUID NULL,
                  attachment_id TEXT NOT NULL,
                  attachment_kind TEXT NOT NULL,
                  source_name TEXT NULL,
                  mime_type TEXT NULL,
                  unit_type TEXT NOT NULL,
                  unit_number INTEGER NULL,
                  title TEXT NULL,
                  excerpt TEXT NOT NULL DEFAULT '',
                  visual_caption TEXT NULL,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  content_hash TEXT NOT NULL,
                  embedding VECTOR(1536),
                  indexed_at TIMESTAMP NULL,
                  created_at TIMESTAMP NULL,
                  updated_at TIMESTAMP NULL
                );
            SQL);
            DB::statement('CREATE UNIQUE INDEX idx_ai_attachment_index_hash ON ai_attachment_index_entries(content_hash);');
            DB::statement('CREATE INDEX idx_ai_attachment_index_thread ON ai_attachment_index_entries(thread_id, indexed_at DESC);');
            DB::statement('CREATE INDEX idx_ai_attachment_index_attachment ON ai_attachment_index_entries(attachment_id);');
            DB::statement('CREATE INDEX idx_ai_attachment_index_embedding ON ai_attachment_index_entries USING ivfflat (embedding vector_cosine_ops);');

            return;
        }

        Schema::create('ai_attachment_index_entries', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->text('attachment_id');
            $table->text('attachment_kind');
            $table->text('source_name')->nullable();
            $table->text('mime_type')->nullable();
            $table->text('unit_type');
            $table->integer('unit_number')->nullable();
            $table->text('title')->nullable();
            $table->text('excerpt')->default('');
            $table->text('visual_caption')->nullable();
            $table->json('metadata')->nullable();
            $table->text('content_hash')->unique();
            $table->json('embedding')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
    }
};
