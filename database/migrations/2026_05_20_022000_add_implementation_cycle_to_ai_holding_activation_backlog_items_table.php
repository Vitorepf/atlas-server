<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_activation_backlog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'evidence_attached_json')) {
                $table->json('evidence_attached_json')->default('[]')->after('evidence_required_json');
            }
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'implementation_receipts_json')) {
                $table->json('implementation_receipts_json')->default('[]')->after('evidence_attached_json');
            }
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'implementation_attempt_count')) {
                $table->unsignedInteger('implementation_attempt_count')->default(0)->after('source_flow_backlog_json');
            }
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'last_implementation_receipt_hash')) {
                $table->string('last_implementation_receipt_hash', 64)->nullable()->index()->after('implementation_attempt_count');
            }
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'blocked_reason')) {
                $table->string('blocked_reason', 180)->nullable()->index()->after('last_implementation_receipt_hash');
            }
            if (! Schema::hasColumn('ai_holding_activation_backlog_items', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable()->index()->after('queued_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_activation_backlog_items', function (Blueprint $table): void {
            foreach ([
                'last_run_at',
                'blocked_reason',
                'last_implementation_receipt_hash',
                'implementation_attempt_count',
                'implementation_receipts_json',
                'evidence_attached_json',
            ] as $column) {
                if (Schema::hasColumn('ai_holding_activation_backlog_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
