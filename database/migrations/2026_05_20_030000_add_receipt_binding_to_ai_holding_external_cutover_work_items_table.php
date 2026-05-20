<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_holding_external_cutover_work_items', function (Blueprint $table): void {
            $table->string('receipt_binding_hash', 64)->nullable()->index()->after('bound_receipt_hash');
            $table->json('receipt_binding_json')->nullable()->after('receipt_binding_hash');
        });
    }

    public function down(): void
    {
        Schema::table('ai_holding_external_cutover_work_items', function (Blueprint $table): void {
            $table->dropColumn(['receipt_binding_hash', 'receipt_binding_json']);
        });
    }
};
