<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('ai_job_id')->nullable()->index();
            $table->uuid('ai_job_attempt_id')->nullable()->index();
            $table->uuid('client_id')->nullable()->index();
            $table->string('surface', 24);
            $table->string('runtime', 32)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('cli_version', 64)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('agent_slug', 120)->nullable();
            $table->string('event_name', 100);
            $table->string('event_phase', 60)->nullable();
            $table->timestamp('occurred_at_client')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->integer('duration_ms')->nullable();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->string('unit', 32)->nullable();
            $table->json('metadata')->default('{}');
            $table->json('privacy')->default('{}');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['surface', 'event_name', 'received_at'], 'idx_ai_telemetry_surface_event_received');
            $table->index(['provider', 'received_at'], 'idx_ai_telemetry_provider_received');
            $table->index(['trace_id', 'received_at'], 'idx_ai_telemetry_trace_received');
            $table->index(['thread_id', 'received_at'], 'idx_ai_telemetry_thread_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_telemetry_events');
    }
};
