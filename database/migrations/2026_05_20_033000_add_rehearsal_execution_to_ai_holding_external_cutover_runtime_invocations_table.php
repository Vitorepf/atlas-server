<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'execution_receipts_json')) {
                $table->json('execution_receipts_json')->nullable()->after('blocked_operations_json');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'last_execution_receipt_hash')) {
                $table->string('last_execution_receipt_hash', 64)->nullable()->after('execution_receipts_json');
                $table->index('last_execution_receipt_hash', 'idx_cutover_runtime_last_receipt');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'execution_receipt_count')) {
                $table->unsignedInteger('execution_receipt_count')->default(0)->after('last_execution_receipt_hash');
                $table->index('execution_receipt_count', 'idx_cutover_runtime_receipt_count');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'last_executed_at')) {
                $table->timestamp('last_executed_at')->nullable()->index('idx_cutover_runtime_last_executed_at')->after('last_status_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            $table->dropColumn([
                'execution_receipts_json',
                'last_execution_receipt_hash',
                'execution_receipt_count',
                'last_executed_at',
            ]);
        });
    }
};
