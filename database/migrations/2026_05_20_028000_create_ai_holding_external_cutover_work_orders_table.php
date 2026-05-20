<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_external_cutover_work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('work_order_id', 64)->unique();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('status', 100)->default('pending_real_receipts_launch_blocked')->index();
            $table->string('phase', 120)->index();
            $table->string('cutover_decision', 140)->index();
            $table->string('source_cutover_dossier_hash', 64)->index();
            $table->string('work_order_hash', 64)->index();
            $table->json('required_receipt_ids_json')->default('[]');
            $table->json('missing_receipt_ids_json')->default('[]');
            $table->json('operator_enablement_pack_json')->default('{}');
            $table->json('launch_blockers_json')->default('[]');
            $table->unsignedInteger('work_item_count')->default(0);
            $table->unsignedInteger('pending_work_item_count')->default(0);
            $table->unsignedInteger('bound_receipt_count')->default(0);
            $table->boolean('work_order_ready')->default(false)->index();
            $table->boolean('supervised_cutover_enabled')->default(false)->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamp('last_status_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'flow_id'], 'uniq_ai_holding_external_cutover_work_order_company_flow');
            $table->index(['company_id', 'status', 'phase'], 'idx_ai_holding_external_cutover_work_order_company_status_phase');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_external_cutover_work_orders_updated_at
                BEFORE UPDATE ON ai_holding_external_cutover_work_orders
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_external_cutover_work_orders');
    }
};
