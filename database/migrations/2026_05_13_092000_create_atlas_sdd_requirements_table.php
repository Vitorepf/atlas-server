<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_requirements')) {
            return;
        }

        Schema::create('atlas_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->index();
            $table->string('code', 32)->index();
            $table->text('text');
            $table->string('priority', 16)->default('must')->index();
            $table->string('status', 32)->default('open')->index();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->unique(['spec_id', 'code'], 'idx_atlas_requirements_spec_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_requirements');
    }
};
