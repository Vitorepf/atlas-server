<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_connector_activation_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('connector_id', 160)->index();
            $table->string('status', 80)->default('registered_needs_probe')->index();
            $table->string('certification_receipt_hash', 64)->nullable()->index();
            $table->string('flow_usage_attestation_hash', 64)->nullable()->index();
            $table->string('adapter_contract_hash', 64)->nullable();
            $table->string('auth_boundary_hash', 64)->nullable();
            $table->string('sandbox_probe_hash', 64)->nullable();
            $table->string('slo_hash', 64)->nullable();
            $table->string('lineage_hash', 64)->nullable();
            $table->string('replay_fixture_hash', 64)->nullable();
            $table->json('allowed_modes_json')->default('[]');
            $table->json('blocked_modes_json')->default('[]');
            $table->json('pre_run_requirements_json')->default('[]');
            $table->json('probe_receipts_json')->default('[]');
            $table->string('last_probe_receipt_hash', 64)->nullable()->index();
            $table->string('last_probe_status', 80)->nullable()->index();
            $table->boolean('vault_binding_required')->default(true)->index();
            $table->boolean('vault_binding_attested')->default(false)->index();
            $table->boolean('sandbox_probe_green')->default(false)->index();
            $table->boolean('slo_monitor_bound')->default(false)->index();
            $table->boolean('reconciliation_bound')->default(false)->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamp('last_probed_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'flow_id', 'connector_id'], 'uniq_ai_holding_connector_activation_flow_connector');
            $table->index(['company_id', 'status'], 'idx_ai_holding_connector_activation_company_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_connector_activation_records_updated_at
                BEFORE UPDATE ON ai_holding_connector_activation_records
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_connector_activation_records');
    }
};
