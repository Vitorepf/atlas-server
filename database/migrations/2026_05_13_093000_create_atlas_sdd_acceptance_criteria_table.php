<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_acceptance_criteria')) {
            return;
        }

        Schema::create('atlas_acceptance_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('requirement_id')->index();
            $table->string('code', 32)->index();
            $table->text('given')->nullable();
            $table->text('when');
            $table->text('then');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->unique(['requirement_id', 'code'], 'idx_atlas_ac_req_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_acceptance_criteria');
    }
};
