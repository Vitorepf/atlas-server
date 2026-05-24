<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_outcome_memories')) {
            return;
        }

        Schema::table('ai_forge_outcome_memories', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_forge_outcome_memories', 'aedpds_drivers')) {
                $table->json('aedpds_drivers')->nullable()->after('evidence_kinds');
            }
            if (! Schema::hasColumn('ai_forge_outcome_memories', 'aedpds_gate_status')) {
                $table->string('aedpds_gate_status', 40)->nullable()->index()->after('aedpds_drivers');
            }
            if (! Schema::hasColumn('ai_forge_outcome_memories', 'aedpds_doctrine_hash')) {
                $table->string('aedpds_doctrine_hash', 64)->nullable()->index()->after('aedpds_gate_status');
            }
            if (! Schema::hasColumn('ai_forge_outcome_memories', 'aedpds_gate_hash')) {
                $table->string('aedpds_gate_hash', 64)->nullable()->index()->after('aedpds_doctrine_hash');
            }
            if (! Schema::hasColumn('ai_forge_outcome_memories', 'aedpds_effectiveness')) {
                $table->json('aedpds_effectiveness')->nullable()->after('aedpds_gate_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_outcome_memories')) {
            return;
        }

        Schema::table('ai_forge_outcome_memories', function (Blueprint $table): void {
            foreach (['aedpds_effectiveness', 'aedpds_gate_hash', 'aedpds_doctrine_hash', 'aedpds_gate_status', 'aedpds_drivers'] as $column) {
                if (Schema::hasColumn('ai_forge_outcome_memories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
