<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_holding_activation_backlog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id', 80)->index();
            $table->string('flow_id', 160)->index();
            $table->string('work_package_id', 120)->index();
            $table->string('status', 80)->default('queued_for_implementation')->index();
            $table->string('activation_stage', 120)->index();
            $table->unsignedInteger('priority_score')->default(0)->index();
            $table->string('mandate_packet_hash', 64)->nullable()->index();
            $table->string('owner', 160)->index();
            $table->string('deliverable', 240);
            $table->json('gap_counts_json')->default('{}');
            $table->json('connector_activation_gaps_json')->default('[]');
            $table->json('governance_gaps_json')->default('[]');
            $table->json('operationalization_gaps_json')->default('[]');
            $table->json('evidence_required_json')->default('[]');
            $table->json('evidence_attached_json')->default('[]');
            $table->json('implementation_receipts_json')->default('[]');
            $table->json('source_flow_backlog_json')->default('{}');
            $table->unsignedInteger('implementation_attempt_count')->default(0);
            $table->string('last_implementation_receipt_hash', 64)->nullable()->index();
            $table->string('blocked_reason', 180)->nullable()->index();
            $table->boolean('manual_handoff_ready')->default(false)->index();
            $table->boolean('external_execution_allowed')->default(false)->index();
            $table->boolean('external_side_effects_enabled')->default(false)->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'flow_id', 'work_package_id'], 'uniq_ai_holding_activation_backlog_work_package');
            $table->index(['company_id', 'status', 'priority_score'], 'idx_ai_holding_activation_backlog_company_status_priority');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_holding_activation_backlog_items_updated_at
                BEFORE UPDATE ON ai_holding_activation_backlog_items
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_holding_activation_backlog_items');
    }
};
