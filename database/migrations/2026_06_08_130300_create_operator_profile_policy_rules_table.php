<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_profile_policy_rules')) {
            return;
        }

        Schema::create('operator_profile_policy_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operator_profile_item_id')->index();
            $table->string('rule_key', 180)->index();
            $table->json('rule');
            $table->string('applies_to_flow', 160)->nullable()->index();
            $table->unsignedSmallInteger('priority')->default(50)->index();
            $table->string('effect', 80)->index();
            $table->string('reverse_handle', 200)->nullable();
            $table->timestamps();

            $table->index(['effect', 'applies_to_flow', 'priority'], 'idx_operator_policy_rules_effect_flow_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_profile_policy_rules');
    }
};
