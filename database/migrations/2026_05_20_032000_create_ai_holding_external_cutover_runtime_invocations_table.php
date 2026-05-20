<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invocation_id', 64)->unique();
            $table->string('work_order_id', 64)->unique();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('status', 120)->index();
            $table->string('mode', 120)->index();
            $table->string('source_work_order_hash', 64)->index();
            $table->string('source_final_authority_binding_hash', 64)->index();
            $table->string('runtime_invocation_packet_hash', 64)->index();
            $table->json('required_final_authorities_json')->default('[]');
            $table->json('final_authority_bindings_json')->default('{}');
            $table->json('operator_runtime_contract_json')->default('{}');
            $table->json('blocked_operations_json')->default('[]');
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamp('last_status_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'flow_id', 'status'], 'idx_cutover_runtime_inv_company_flow_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_external_cutover_runtime_invocations_updated_at
                BEFORE UPDATE ON ai_holding_external_cutover_runtime_invocations
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_external_cutover_runtime_invocations');
    }
};
