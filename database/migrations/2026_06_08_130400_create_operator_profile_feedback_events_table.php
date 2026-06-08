<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_profile_feedback_events')) {
            return;
        }

        Schema::create('operator_profile_feedback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operator_profile_item_id')->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('session_id', 120)->nullable()->index();
            $table->string('feedback_action', 80)->index();
            $table->decimal('feedback_score', 5, 3)->nullable();
            $table->json('outcome')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['operator_profile_item_id', 'created_at'], 'idx_operator_feedback_item_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_profile_feedback_events');
    }
};
