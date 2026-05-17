<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_specialist_flow_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('runtime_schema_version', 80)->nullable();
            $table->string('execution_schema_version', 80)->nullable();
            $table->string('flow_id', 80)->index();
            $table->string('handler_id', 120)->nullable()->index();
            $table->string('handler_version', 32)->nullable();
            $table->string('status', 40)->default('ready_for_provider')->index();
            $table->string('runtime_receipt_id', 80)->nullable()->index();
            $table->string('runtime_contract_hash', 64)->nullable()->index();
            $table->string('delegation_status', 64)->nullable()->index();
            $table->string('delegation_target_flow_id', 80)->nullable()->index();
            $table->json('runtime_payload')->nullable();
            $table->json('execution_payload')->nullable();
            $table->json('receipt')->nullable();
            $table->json('delegation')->nullable();
            $table->json('audit_checks')->nullable();
            $table->json('response_shape')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'status', 'created_at']);
            $table->index(['flow_id', 'handler_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_specialist_flow_executions_updated_at
                BEFORE UPDATE ON ai_specialist_flow_executions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_specialist_flow_executions');
    }
};
