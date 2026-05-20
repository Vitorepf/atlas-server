<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_external_cutover_work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_external_cutover_work_orders', 'final_authority_bindings_json')) {
                $table->json('final_authority_bindings_json')->nullable()->after('launch_blockers_json');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_work_orders', 'final_authority_binding_hash')) {
                $table->string('final_authority_binding_hash', 64)->nullable()->after('final_authority_bindings_json');
                $table->index('final_authority_binding_hash', 'idx_cutover_final_auth_hash');
            }
            if (! Schema::hasColumn('ai_holding_external_cutover_work_orders', 'final_authority_binding_count')) {
                $table->unsignedInteger('final_authority_binding_count')->default(0)->after('bound_receipt_count');
                $table->index('final_authority_binding_count', 'idx_cutover_final_auth_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_external_cutover_work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'final_authority_bindings_json',
                'final_authority_binding_hash',
                'final_authority_binding_count',
            ]);
        });
    }
};
