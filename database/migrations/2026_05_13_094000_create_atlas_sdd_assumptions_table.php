<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_assumptions')) {
            return;
        }

        Schema::create('atlas_assumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->index();
            $table->text('text');
            // confidence_class: confirmed_fact|strong_inference|hypothesis|blocking_ambiguity
            $table->string('confidence_class', 32)->index();
            $table->boolean('blocking')->default(false)->index();
            $table->json('evidence_json')->default('[]');
            $table->json('clarification_questions_json')->default('[]');
            $table->string('resolved_status', 32)->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_assumptions');
    }
};
