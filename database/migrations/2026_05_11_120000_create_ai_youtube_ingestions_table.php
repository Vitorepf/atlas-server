<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_youtube_ingestions')) {
            return;
        }

        Schema::create('ai_youtube_ingestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('video_id', 32)->unique();
            $table->text('url');
            $table->text('title')->nullable();
            $table->string('channel')->nullable();
            $table->string('status', 48)->index();
            $table->text('reason')->nullable();
            $table->string('metadata_source', 96)->nullable()->index();
            $table->string('caption_kind', 64)->nullable();
            $table->string('caption_language', 24)->nullable();
            $table->string('audio_fallback_status', 64)->nullable()->index();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('transcript_chars')->default(0);
            $table->unsignedInteger('ingestion_ms')->nullable();
            $table->boolean('cache_hit')->default(false);
            $table->json('metadata')->nullable();
            $table->json('caption')->nullable();
            $table->json('chunks')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('last_ingested_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'last_ingested_at'], 'idx_ai_youtube_ingestions_status_last');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_youtube_ingestions');
    }
};
