<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_enterprise_flow_operations_runbooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('status', 100)->default('registered_needs_operations_drill')->index();
            $table->uuid('source_queue_item_id')->nullable()->index();
            $table->string('runbook_hash', 64)->index();
            $table->string('operating_package_hash', 64)->nullable()->index();
            $table->json('operating_package_json')->default('{}');
            $table->json('replay_contract_json')->default('{}');
            $table->json('operating_package_attestations_json')->default('[]');
            $table->json('slo_contract_json')->default('{}');
            $table->json('incident_route_json')->default('{}');
            $table->json('reconciliation_contract_json')->default('{}');
            $table->json('promotion_gates_json')->default('[]');
            $table->json('dashboard_bindings_json')->default('[]');
            $table->json('drill_receipts_json')->default('[]');
            $table->string('last_drill_receipt_hash', 64)->nullable()->index();
            $table->boolean('slo_green')->default(false)->index();
            $table->boolean('incident_route_green')->default(false)->index();
            $table->boolean('reconciliation_green')->default(false)->index();
            $table->boolean('promotion_gate_green')->default(false)->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamp('last_drilled_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'flow_id'], 'uniq_ai_holding_flow_operations_runbook_company_flow');
            $table->index(['company_id', 'status'], 'idx_ai_holding_flow_operations_runbook_company_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_enterprise_flow_operations_runbooks_updated_at
                BEFORE UPDATE ON ai_holding_enterprise_flow_operations_runbooks
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_enterprise_flow_operations_runbooks');
    }
};
