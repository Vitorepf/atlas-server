<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_autonomy_tier_promotions')) {
            return;
        }

        Schema::create('atlas_autonomy_tier_promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('area_id', 120)->index();
            $table->unsignedTinyInteger('tier');
            $table->string('receipt_hash', 64);
            $table->boolean('operator_signed');
            $table->timestamp('decided_at')->index();
            $table->json('receipt_payload');
            $table->timestamps();

            $table->index(['area_id', 'decided_at'], 'idx_autonomy_tier_area_decided');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_autonomy_tier_promotions');
    }
};
