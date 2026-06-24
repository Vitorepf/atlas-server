<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_keyword_decision_ledger')) {
            return;
        }

        Schema::create('ai_keyword_decision_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_hash', 40)->unique();   // sha1 — idempotency key (mesma decisão = 1 linha)
            $table->string('run_hash', 64)->nullable()->index();
            $table->string('keyword', 255)->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('band', 20)->nullable();
            $table->string('family', 60)->nullable();
            $table->string('intent_tier', 4)->nullable();
            $table->string('investment_verdict', 20)->nullable();
            $table->string('investment_basis', 30)->nullable();
            $table->string('account_risk', 20)->default('none');
            $table->string('core_version', 20);
            $table->json('receipt');                          // o Decision-Receipt inteiro (decisão + proveniência)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_keyword_decision_ledger');
    }
};
