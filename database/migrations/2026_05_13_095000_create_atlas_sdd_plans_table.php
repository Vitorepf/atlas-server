<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_plans')) {
            return;
        }

        Schema::create('atlas_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->index();
            $table->string('content_hash', 64)->index();
            $table->json('content_json');
            $table->json('target_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('hot_file_ownership_json')->default('{}');
            $table->json('test_plan_json')->default('[]');
            $table->json('rollback_plan_json')->default('{}');
            $table->string('status', 32)->default('draft')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_plans');
    }
};
