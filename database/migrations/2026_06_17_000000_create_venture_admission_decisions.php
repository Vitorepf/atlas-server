<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_venture_admission_decisions')) {
            Schema::create('ai_venture_admission_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.admission_decision.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->nullable()->index();
                $table->uuid('idea_id')->nullable()->index();
                $table->string('decision', 20)->index(); // admitted | rejected | parked
                $table->string('origin', 20)->default('created')->index(); // created | managed
                $table->text('reason')->nullable();
                $table->uuid('assessment_run_id')->nullable()->index();
                $table->decimal('score', 7, 3)->nullable();
                $table->json('success_milestone')->nullable();
                $table->string('decided_by', 120)->default('atlas');
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_hash', 64)->unique();
                $table->timestamps();

                $table->index(['venture_id', 'created_at'], 'idx_venture_admission_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_venture_admission_decisions');
    }
};
