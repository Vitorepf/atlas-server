<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dreyfus_overlays')) {
            return;
        }

        Schema::create('dreyfus_overlays', function (Blueprint $table): void {
            $table->id();
            $table->uuid('knowledge_node_id');
            $table->string('domain', 64);
            $table->string('specialist_profile', 64)->nullable();
            $table->unsignedTinyInteger('current_level');
            $table->decimal('confidence', 3, 2);
            $table->json('evidence_refs')->nullable();
            $table->string('last_updated_via', 64);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('next_validation_at')->nullable();
            $table->timestamps();

            $table->index(['knowledge_node_id', 'domain']);
            $table->index('current_level');
            $table->unique(['knowledge_node_id', 'domain'], 'dreyfus_overlay_node_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreyfus_overlays');
    }
};
