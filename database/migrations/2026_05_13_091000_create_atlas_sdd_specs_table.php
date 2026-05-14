<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_specs')) {
            return;
        }

        Schema::create('atlas_specs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('work_item_id')->nullable()->index();
            $table->string('title', 255);
            $table->string('type', 64)->default('feature')->index();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('risk_level', 16)->default('medium')->index();
            $table->string('content_hash', 64)->index();
            $table->json('content_json');
            $table->text('content_markdown')->nullable();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamp('superseded_at')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamps();

            $table->index(['operation_id', 'status'], 'idx_atlas_specs_op_status');
            $table->index(['status', 'created_at'], 'idx_atlas_specs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_specs');
    }
};
