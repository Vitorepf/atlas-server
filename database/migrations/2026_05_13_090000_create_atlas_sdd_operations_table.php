<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_operations')) {
            return;
        }

        Schema::create('atlas_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 80)->nullable()->index();
            $table->string('user_id', 80)->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('work_item_id')->nullable()->index();
            $table->text('raw_input');
            $table->text('interpreted_intent')->nullable();
            $table->string('domain', 80)->nullable()->index();
            $table->string('status', 32)->default('received')->index();
            $table->string('risk_level', 16)->default('medium')->index();
            $table->string('confidence_class', 32)->nullable()->index();
            $table->json('routing_metadata_json')->default('{}');
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_atlas_operations_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_operations');
    }
};
