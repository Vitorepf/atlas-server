<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_aemor_judgment_reports', function (Blueprint $table): void {
            $table->json('outcome_attribution')->nullable()->after('causality_rank');
            $table->json('negative_knowledge')->nullable()->after('human_correction');
            $table->json('provider_skill_reliability')->nullable()->after('negative_knowledge');
            $table->json('counterfactual_replay')->nullable()->after('provider_skill_reliability');
            $table->json('memory_budget')->nullable()->after('counterfactual_replay');
            $table->json('operational_doctrine')->nullable()->after('memory_budget');
        });
    }

    public function down(): void
    {
        Schema::table('atlas_aemor_judgment_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'outcome_attribution',
                'negative_knowledge',
                'provider_skill_reliability',
                'counterfactual_replay',
                'memory_budget',
                'operational_doctrine',
            ]);
        });
    }
};
