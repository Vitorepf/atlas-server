<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_marketing_pattern_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vsl_asset_id')->nullable()->index();
            $table->string('niche', 80)->index();           // 'weight_loss', 'finance', 'relationship', …
            $table->string('page_kind', 40);                // 'bridge', 'vsl_page', 'rsa', 'email'
            $table->json('present_patterns');               // ['library:key', …] from the audit fingerprint
            $table->json('audit_snapshot');                 // full ConversionAuditor.audit() at the time
            $table->integer('hollowness');                  // AntiGoodhartGuard hollowness at the time
            $table->float('conversion_rate')->nullable();   // 0.0–1.0, when reported (CVR from postback)
            $table->integer('clicks')->nullable();
            $table->integer('conversions')->nullable();
            $table->float('revenue_per_visitor')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['niche', 'page_kind']);
            $table->index(['niche', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_pattern_outcomes');
    }
};
