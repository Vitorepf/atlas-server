<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_external_cutover_work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('work_item_id', 64)->unique();
            $table->string('work_order_id', 64)->index();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('action_id', 120)->index();
            $table->string('workstream', 80)->index();
            $table->string('owner_role', 120)->index();
            $table->string('status', 100)->default('pending_real_receipt_or_operator_action')->index();
            $table->json('required_receipt_ids_json')->default('[]');
            $table->json('completion_requires_json')->default('[]');
            $table->json('blocked_operations_json')->default('[]');
            $table->string('source_cutover_dossier_hash', 64)->index();
            $table->string('work_item_hash', 64)->index();
            $table->string('bound_receipt_hash', 64)->nullable()->index();
            $table->boolean('executable')->default(false)->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['work_order_id', 'action_id'], 'uniq_ai_holding_external_cutover_work_item_order_action');
            $table->index(['company_id', 'flow_id', 'status'], 'idx_ai_holding_external_cutover_work_item_company_flow_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_external_cutover_work_items_updated_at
                BEFORE UPDATE ON ai_holding_external_cutover_work_items
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_external_cutover_work_items');
    }
};
