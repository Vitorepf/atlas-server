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

            // identity + source
            $table->string('content_hash', 64)->unique();          // sha256 of the file → dedupe
            $table->string('label', 300);
            $table->string('source_type', 24)->default('file');    // file | url
            $table->text('source_ref');                            // absolute path or url
            $table->string('source_filename', 300)->nullable();
            $table->string('campaign_ref', 200)->nullable()->index(); // operator's campaign tag
            $table->string('niche', 200)->nullable()->index();
            $table->string('sub_niche', 200)->nullable();
            $table->string('language', 24)->default('pt');
            $table->unsignedInteger('duration_seconds')->nullable();

            // ingestion lifecycle: pending|processing|transcribed|analyzing|structured|failed
            $table->string('status', 48)->default('pending')->index();
            $table->text('reason')->nullable();

            // raw transcript — the heart of the offer (TOAST handles 2h+ out-of-line)
            $table->longText('transcript')->nullable();
            $table->unsignedInteger('transcript_chars')->default(0);
            $table->json('transcript_segments')->nullable();       // persuasion beats w/ approx timestamps
            $table->text('essential_summary')->nullable();         // only the essentials, distilled

            // core direct-response anatomy (first-class, queryable)
            $table->text('big_idea')->nullable();
            $table->text('core_promise')->nullable();
            $table->text('problem_mechanism')->nullable();         // mecanismo do problema (separate column)
            $table->text('solution_mechanism')->nullable();        // mecanismo da solução (separate column)
            $table->string('awareness_level', 48)->nullable();     // Schwartz awareness
            $table->string('sophistication_level', 48)->nullable();
            $table->unsignedInteger('pitch_starts_at_seconds')->nullable();

            // rich nested intelligence
            $table->json('avatar')->nullable();        // demographics, pains, desires, objections, failed_solutions
            $table->json('offer')->nullable();         // product, price, stack, bonuses, guarantee, upsells
            $table->json('persuasion')->nullable();    // hook, story, proof, emotional_drivers, close, scarcity
            $table->json('claims')->nullable();        // strong claims + compliance risk level
            $table->json('levers')->nullable();        // actionable lever map for the Stage-1 decision engine
            $table->json('funnel_kit')->nullable();    // presell angle, ad angles, keyword themes
            $table->json('compliance')->nullable();    // Google policy risks + safer rephrasings

            // extraction lifecycle
            $table->string('structure_status', 32)->nullable()->index(); // pending|ready|failed
            $table->string('extraction_model', 96)->nullable();
            $table->timestamp('structured_at')->nullable();

            // diagnostics
            $table->unsignedInteger('transcription_ms')->nullable();
            $table->string('transcription_engine', 96)->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('last_ingested_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'last_ingested_at'], 'idx_ai_marketing_vsl_assets_status_last');
            $table->index(['niche', 'status'], 'idx_ai_marketing_vsl_assets_niche_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_vsl_assets');
    }
};
