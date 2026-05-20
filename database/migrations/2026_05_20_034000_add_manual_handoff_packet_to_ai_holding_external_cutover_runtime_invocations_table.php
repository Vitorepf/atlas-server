<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_handoff_packet_json')) {
                $table->json('manual_handoff_packet_json')->nullable()->after('last_executed_at');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_handoff_packet_hash')) {
                $table->string('manual_handoff_packet_hash', 64)->nullable()->after('manual_handoff_packet_json');
                $table->index('manual_handoff_packet_hash', 'idx_cutover_runtime_handoff_hash');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_runtime_invocations', 'manual_handoff_registered_at')) {
                $table->timestamp('manual_handoff_registered_at')->nullable()->index('idx_cutover_runtime_handoff_at')->after('manual_handoff_packet_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_external_cutover_runtime_invocations', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_handoff_packet_json',
                'manual_handoff_packet_hash',
                'manual_handoff_registered_at',
            ]);
        });
    }
};
