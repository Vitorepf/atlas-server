<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_intakes') || ! Schema::hasColumn('ai_forge_intakes', 'escalation_packet_id')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            $table->unique('escalation_packet_id', 'uniq_fi_escalation_packet_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_intakes')) {
            return;
        }

        Schema::table('ai_forge_intakes', function (Blueprint $table): void {
            $table->dropUnique('uniq_fi_escalation_packet_id');
        });
    }
};
