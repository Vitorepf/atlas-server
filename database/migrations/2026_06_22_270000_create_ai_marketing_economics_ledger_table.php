<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-niche unit-economics history. Where the decision ledger records action→outcome, this records
 * the ECONOMIC outcome (max_cpa achieved, actual CVR/ROAS/margin/EPC) so CampaignEconomicsCalculator
 * can use niche-historical priors instead of generic assumptions once real data accumulates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_marketing_economics_ledger')) {
            return;
        }

        Schema::create('ai_marketing_economics_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_economics.v1');
            $table->string('campaign_ref', 200)->nullable()->index();
            $table->string('niche', 80)->index();
            $table->string('offer_type', 60)->nullable();
            $table->decimal('payout', 12, 2)->nullable();
            $table->decimal('cvr_actual', 8, 5)->nullable();
            $table->decimal('refund_rate_actual', 6, 4)->nullable();
            $table->decimal('max_cpa_set', 12, 2)->nullable();
            $table->decimal('max_cpa_achieved', 12, 2)->nullable();
            $table->decimal('roas_actual', 10, 4)->nullable();
            $table->decimal('margin_actual', 6, 4)->nullable();
            $table->decimal('epc', 10, 4)->nullable();
            $table->decimal('revenue', 14, 2)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_economics_ledger');
    }
};
