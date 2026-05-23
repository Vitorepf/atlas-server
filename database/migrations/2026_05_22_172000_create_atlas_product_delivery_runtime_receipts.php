<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_product_delivery_runtime_receipts')) {
            return;
        }

        Schema::create('atlas_product_delivery_runtime_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.product_delivery.runtime_receipt.v1');
            $table->string('receipt_type', 80)->index();
            $table->string('status', 80)->index();
            $table->string('route', 40)->nullable()->index();
            $table->string('delivery_hash', 64)->nullable()->index();
            $table->string('proof_hash', 64)->nullable()->index();
            $table->json('payload');
            $table->boolean('writes')->default(false)->index();
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();

            $table->index(['receipt_type', 'created_at'], 'idx_apd_runtime_receipts_type_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_product_delivery_runtime_receipts');
    }
};
