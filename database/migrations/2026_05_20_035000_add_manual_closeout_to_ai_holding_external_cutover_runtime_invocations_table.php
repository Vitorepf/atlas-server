<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_closeout_receipts_json')) {
                $table->json('manual_closeout_receipts_json')->nullable()->after('manual_handoff_registered_at');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'last_manual_closeout_receipt_hash')) {
                $table->string('last_manual_closeout_receipt_hash', 64)->nullable()->after('manual_closeout_receipts_json');
                $table->index('last_manual_closeout_receipt_hash', 'idx_cutover_runtime_closeout_hash');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_closeout_receipt_count')) {
                $table->unsignedInteger('manual_closeout_receipt_count')->default(0)->after('last_manual_closeout_receipt_hash');
                $table->index('manual_closeout_receipt_count', 'idx_cutover_runtime_closeout_count');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_closeout_registered_at')) {
                $table->timestamp('manual_closeout_registered_at')->nullable()->index('idx_cutover_runtime_closeout_at')->after('manual_closeout_receipt_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_closeout_receipts_json',
                'last_manual_closeout_receipt_hash',
                'manual_closeout_receipt_count',
                'manual_closeout_registered_at',
            ]);
        });
    }
};
