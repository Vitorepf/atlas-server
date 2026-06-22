<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_marketing_campaign_blueprints')) {
            return;
        }

        Schema::create('ai_marketing_campaign_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_campaign_blueprint.v1');
            $table->uuid('vsl_asset_id')->index();
            $table->string('campaign_ref', 200)->nullable()->index();
            $table->string('channel', 48)->default('google_search');
            $table->string('geo', 120)->nullable();
            $table->string('language', 24)->nullable();

            $table->json('inputs')->nullable();          // payout, refund, margin, cvr, daily_budget
            $table->json('economics')->nullable();       // max_cpa, breakeven_cpc, target_roas, thresholds
            $table->json('bid_plan')->nullable();        // phased bid strategy
            $table->json('structure')->nullable();       // account/campaign structure
            $table->json('keywords_plan')->nullable();   // ad groups + match types + negatives + launch order
            $table->json('angle')->nullable();           // chosen angle + funnel stages + page types
            $table->json('ad_plan')->nullable();         // RSA per ad group
            $table->json('first_test_plan')->nullable(); // budget, sample, kill/scale thresholds

            $table->string('status', 40)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vsl_asset_id', 'channel'], 'idx_ai_mkt_blueprints_vsl_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_campaign_blueprints');
    }
};
