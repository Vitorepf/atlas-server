<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_marketing_winning_patterns')) {
            return;
        }

        Schema::create('ai_marketing_winning_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_winning_pattern.v1');
            $table->string('niche', 80)->unique();
            $table->string('source', 40)->default('nivor');
            $table->decimal('real_cvr', 8, 5)->nullable();      // robust click->sale CVR (fraction)
            $table->json('cvr_stats')->nullable();
            $table->json('converting_keywords')->nullable();    // [{term, conversions}] ranked
            $table->json('winning_pages')->nullable();          // [{url, conversions}]
            $table->json('winning_funnels')->nullable();        // [{domain, conversions}]
            $table->json('campaigns_sample')->nullable();
            $table->unsignedInteger('campaigns_count')->default(0);
            $table->unsignedInteger('sales_total')->default(0);
            $table->unsignedInteger('clicks_total')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_winning_patterns');
    }
};
