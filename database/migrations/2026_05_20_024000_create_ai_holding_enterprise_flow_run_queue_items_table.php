<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_enterprise_flow_run_queue_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('status', 80)->default('queued_for_internal_execution')->index();
            $table->unsignedInteger('priority_score')->default(0)->index();
            $table->string('activation_stage', 120)->index();
            $table->string('mandate_packet_hash', 64)->nullable()->index();
            $table->string('operating_package_hash', 64)->nullable()->index();
            $table->json('operating_package_json')->default('{}');
            $table->json('replay_contract_json')->default('{}');
            $table->json('operating_package_attestations_json')->default('[]');
            $table->unsignedInteger('connector_activation_count')->default(0);
            $table->unsignedInteger('connector_probe_green_count')->default(0);
            $table->unsignedInteger('work_package_count')->default(0);
            $table->unsignedInteger('completed_work_package_count')->default(0);
            $table->unsignedInteger('blocked_work_package_count')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('queue_receipts_json')->default('[]');
            $table->json('execution_receipts_json')->default('[]');
            $table->string('last_execution_receipt_hash', 64)->nullable()->index();
            $table->string('dlq_reason', 180)->nullable()->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('leased_at')->nullable()->index();
            $table->timestamp('last_executed_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('dlq_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'flow_id'], 'uniq_ai_holding_enterprise_flow_run_queue_company_flow');
            $table->index(['company_id', 'status', 'priority_score'], 'idx_ai_holding_enterprise_flow_run_queue_company_status_priority');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_enterprise_flow_run_queue_items_updated_at
                BEFORE UPDATE ON ai_holding_enterprise_flow_run_queue_items
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_enterprise_flow_run_queue_items');
    }
};
