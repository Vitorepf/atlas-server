<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_profile_items')) {
            return;
        }

        Schema::create('operator_profile_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id', 120)->index();
            $table->string('taxonomy_item_id', 40)->index();
            $table->string('profile_key', 160)->index();
            $table->json('value')->nullable();
            $table->text('summary');
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 160)->nullable()->index();
            $table->string('validity_kind', 40)->default('permanent')->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->decimal('confidence', 5, 3)->default(0.500);
            $table->string('privacy_class', 40)->default('normal')->index();
            $table->string('automation_level', 60)->default('observe')->index();
            $table->string('status', 40)->default('active')->index();
            $table->uuid('source_candidate_id')->nullable()->index();
            $table->uuid('source_memory_entry_id')->nullable()->index();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_id', 'profile_key', 'scope_type', 'scope_id'], 'uq_operator_profile_item_scope_key');
            $table->index(['operator_id', 'status', 'confidence'], 'idx_operator_profile_items_active_confidence');
            $table->index(['operator_id', 'taxonomy_item_id', 'status'], 'idx_operator_profile_items_taxonomy_status');
            $table->index(['privacy_class', 'automation_level'], 'idx_operator_profile_items_privacy_automation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_profile_items');
    }
};
