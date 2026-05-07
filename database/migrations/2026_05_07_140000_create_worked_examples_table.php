<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('worked_examples')) {
            return;
        }

        Schema::create('worked_examples', function (Blueprint $table): void {
            $table->id();
            $table->uuid('knowledge_node_id');
            $table->string('domain', 64);
            $table->string('specialist_profile', 64)->nullable();
            $table->string('title', 200);
            $table->text('problem_context');
            $table->json('solution_full');
            $table->json('fading_levels');
            $table->string('source', 32);
            $table->json('author_evidence_refs')->nullable();
            $table->unsignedInteger('delivered_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->index(['knowledge_node_id', 'domain']);
            $table->index('source');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worked_examples');
    }
};
