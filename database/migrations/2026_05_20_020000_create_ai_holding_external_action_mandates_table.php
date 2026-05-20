<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_external_action_mandates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('status', 80)->default('queued_for_operator_review')->index();
            $table->string('mandate_packet_hash', 64)->unique();
            $table->string('source_runtime_receipt_hash', 64)->index();
            $table->string('source_connector_certification_hash', 64)->index();
            $table->string('source_flow_usage_attestation_hash', 64)->nullable()->index();
            $table->json('connector_scope_json')->default('[]');
            $table->json('blocked_operations_json')->default('[]');
            $table->json('preflight_checks_json')->default('[]');
            $table->json('risk_controls_json')->default('{}');
            $table->json('rollback_or_compensation_json')->default('{}');
            $table->json('incident_route_json')->default('{}');
            $table->json('cost_budget_envelope_json')->default('{}');
            $table->boolean('operator_signature_required')->default(true)->index();
            $table->boolean('second_reviewer_required')->default(true)->index();
            $table->boolean('auto_execute_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->json('packet_json')->default('{}');
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('preflighted_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'flow_id', 'status'], 'idx_ai_holding_ext_mandates_company_flow_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_external_action_mandates_updated_at
                BEFORE UPDATE ON ai_holding_external_action_mandates
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_external_action_mandates');
    }
};
