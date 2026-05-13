<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_reviews')) {
            return;
        }

        Schema::create('atlas_programming_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id')->index();
            $table->string('result', 32)->index();
            $table->text('summary')->nullable();
            $table->text('risk_notes')->nullable();
            $table->string('decided_by', 80)->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamps();

            $table->index(['work_item_id', 'created_at'], 'idx_atlas_prog_reviews_item_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_reviews');
    }
};
