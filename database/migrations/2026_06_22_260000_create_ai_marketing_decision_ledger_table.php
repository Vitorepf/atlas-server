<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MOAT: an append-only ledger of every Stage-1 decision (symptom + offer state + action +
 * lever) and its later result. This is what makes Atlas's marketing judgment COMPOUND across
 * offers — "for this symptom, this lever worked X/Y times" — instead of one-shot cleverness.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_marketing_decision_ledger')) {
            return;
        }

        Schema::create('ai_marketing_decision_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_decision.v1');

            // What was decided on
            $table->string('campaign_ref', 200)->nullable()->index();
            $table->uuid('vsl_asset_id')->nullable()->index();
            $table->string('niche', 80)->nullable()->index();

            // The diagnosis → action (from MarketingSymptomActionTree)
            $table->string('stage', 60)->index();          // funnel stage / branch
            $table->text('symptom');
            $table->string('action', 60)->index();         // canonical 9-action space
            $table->string('lever', 200);                  // skill used
            $table->string('vsl_block', 60)->nullable();   // anatomy block edited
            $table->text('numeric_rule')->nullable();
            $table->text('predicted_effect')->nullable();
            $table->json('offer_state')->nullable();        // funnel + economics snapshot at decision
            $table->timestamp('decided_at')->nullable()->index();

            // The result (filled in later — this closes the loop)
            $table->string('outcome', 30)->nullable()->index(); // improved|no_change|worse|pending
            $table->json('result_metrics')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('measured_at')->nullable();

            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_decision_ledger');
    }
};
