<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        Schema::create('ai_marketing_vsl_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_vsl_asset.v1');
            $table->string('content_hash', 64)->unique();
            $table->string('label', 300);
            $table->string('source_type', 24)->default('file');   // file | url
            $table->text('source_ref');                            // absolute file path or url
            $table->string('source_filename', 300)->nullable();
            $table->string('niche', 200)->nullable()->index();
            $table->string('language', 24)->default('pt');
            $table->unsignedInteger('duration_seconds')->nullable();

            // ingestion lifecycle: pending | processing | transcribed | structured | failed
            $table->string('status', 48)->default('pending')->index();
            $table->text('reason')->nullable();

            // raw transcript (the heart of the offer)
            $table->longText('transcript')->nullable();
            $table->unsignedInteger('transcript_chars')->default(0);
            $table->json('segments')->nullable();

            // extracted offer structure (filled by the structure extractor slice)
            $table->json('structure')->nullable();
            $table->string('structure_status', 32)->nullable()->index(); // pending | ready | failed

            $table->unsignedInteger('transcription_ms')->nullable();
            $table->string('transcription_engine', 64)->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('last_ingested_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'last_ingested_at'], 'idx_ai_marketing_vsl_assets_status_last');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_vsl_assets');
    }
};
