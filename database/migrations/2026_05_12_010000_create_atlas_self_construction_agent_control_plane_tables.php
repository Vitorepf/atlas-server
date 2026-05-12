<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_self_construction_agent_runs')) {
            Schema::create('atlas_self_construction_agent_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('run_key', 160)->unique();
                $table->string('packet_id', 160)->nullable()->index();
                $table->string('reservation_id', 160)->nullable()->index();
                $table->string('actor', 120)->index();
                $table->string('provider', 80)->index();
                $table->string('provider_role', 120)->nullable()->index();
                $table->string('session_id', 180)->index();
                $table->string('workspace_id', 160)->nullable()->index();
                $table->string('obra_id', 160)->nullable()->index();
                $table->string('status', 40)->default('queued')->index();
                $table->string('liveness', 40)->default('unknown')->index();
                $table->string('packet_hash', 64)->nullable();
                $table->string('allowed_files_hash', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable()->index();
                $table->timestamp('last_heartbeat_at')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->unsignedBigInteger('input_tokens')->nullable();
                $table->unsignedBigInteger('output_tokens')->nullable();
                $table->decimal('cost_usd', 12, 6)->nullable();
                $table->string('completion_evidence_hash', 64)->nullable();
                $table->text('summary')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['packet_id', 'status'], 'idx_self_construction_runs_packet_status');
                $table->index(['provider', 'status'], 'idx_self_construction_runs_provider_status');
                $table->index(['session_id', 'status'], 'idx_self_construction_runs_session_status');
            });
        }

        if (! Schema::hasTable('atlas_self_construction_agent_heartbeats')) {
            Schema::create('atlas_self_construction_agent_heartbeats', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('agent_run_id')
                    ->constrained('atlas_self_construction_agent_runs')
                    ->cascadeOnDelete();
                $table->string('heartbeat_key', 180)->unique();
                $table->unsignedInteger('sequence')->default(1);
                $table->string('status', 40)->default('alive')->index();
                $table->string('signal', 80)->default('heartbeat')->index();
                $table->timestamp('occurred_at')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['agent_run_id', 'sequence'], 'idx_self_construction_heartbeats_run_sequence');
                $table->index(['agent_run_id', 'occurred_at'], 'idx_self_construction_heartbeats_run_time');
            });
        }

        if (! Schema::hasTable('atlas_self_construction_agent_cost_events')) {
            Schema::create('atlas_self_construction_agent_cost_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('agent_run_id')
                    ->constrained('atlas_self_construction_agent_runs')
                    ->cascadeOnDelete();
                $table->string('cost_event_key', 180)->unique();
                $table->string('provider', 80)->index();
                $table->string('model', 160)->nullable()->index();
                $table->unsignedBigInteger('input_tokens')->default(0);
                $table->unsignedBigInteger('output_tokens')->default(0);
                $table->decimal('cost_usd', 12, 6)->default(0);
                $table->timestamp('occurred_at')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['agent_run_id', 'occurred_at'], 'idx_self_construction_cost_events_run_time');
                $table->index(['provider', 'model'], 'idx_self_construction_cost_events_provider_model');
            });
        }

        if (! Schema::hasTable('atlas_self_construction_agent_work_products')) {
            Schema::create('atlas_self_construction_agent_work_products', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('agent_run_id')
                    ->constrained('atlas_self_construction_agent_runs')
                    ->cascadeOnDelete();
                $table->string('work_product_key', 180)->unique();
                $table->string('packet_id', 160)->nullable()->index();
                $table->string('actor', 120)->index();
                $table->string('provider', 80)->index();
                $table->string('artifact_type', 120)->index();
                $table->text('artifact_path')->nullable();
                $table->string('artifact_hash', 64)->nullable()->index();
                $table->string('status', 40)->default('recorded')->index();
                $table->text('summary')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['packet_id', 'artifact_type'], 'idx_self_construction_work_products_packet_type');
                $table->index(['agent_run_id', 'artifact_type'], 'idx_self_construction_work_products_run_type');
            });
        }

        if (! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            Schema::create('atlas_self_construction_agent_wakeup_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('agent_run_id')
                    ->nullable()
                    ->constrained('atlas_self_construction_agent_runs')
                    ->nullOnDelete();
                $table->string('wakeup_key', 180)->unique();
                $table->string('packet_id', 160)->nullable()->index();
                $table->string('actor', 120)->nullable()->index();
                $table->string('provider', 80)->nullable()->index();
                $table->string('reason', 120)->index();
                $table->string('priority', 40)->default('normal')->index();
                $table->string('status', 40)->default('queued')->index();
                $table->timestamp('scheduled_for')->nullable()->index();
                $table->timestamp('claimed_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['status', 'scheduled_for'], 'idx_self_construction_wakeup_status_schedule');
                $table->index(['provider', 'status'], 'idx_self_construction_wakeup_provider_status');
            });
        }

        if (! Schema::hasTable('atlas_self_construction_agent_dispatch_receipts')) {
            Schema::create('atlas_self_construction_agent_dispatch_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('agent_run_id')
                    ->nullable()
                    ->constrained('atlas_self_construction_agent_runs')
                    ->nullOnDelete();
                $table->foreignUuid('wakeup_item_id')
                    ->nullable()
                    ->constrained('atlas_self_construction_agent_wakeup_items')
                    ->nullOnDelete();
                $table->string('receipt_key', 180)->unique();
                $table->string('packet_id', 160)->nullable()->index();
                $table->string('provider', 80)->index();
                $table->string('provider_role', 120)->nullable()->index();
                $table->string('decision', 80)->index();
                $table->string('status', 40)->default('draft')->index();
                $table->string('signed_by', 160)->nullable()->index();
                $table->timestamp('signed_at')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('used_at')->nullable()->index();
                $table->string('dispatch_envelope_hash', 64)->index();
                $table->string('adapter_contract_hash', 64)->nullable()->index();
                $table->string('receipt_hash', 64)->unique();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['provider', 'status'], 'idx_self_construction_dispatch_receipts_provider_status');
                $table->index(['packet_id', 'status'], 'idx_self_construction_dispatch_receipts_packet_status');
                $table->index(['decision', 'status'], 'idx_self_construction_dispatch_receipts_decision_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
    }
};
