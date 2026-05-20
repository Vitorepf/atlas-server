<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_holding_enterprise_flow_operations_runbooks')) {
            return;
        }

        Schema::table('ai_holding_enterprise_flow_operations_runbooks', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_holding_enterprise_flow_operations_runbooks', 'operating_package_hash')) {
                $table->string('operating_package_hash', 64)->nullable()->index()->after('runbook_hash');
            }
            if (! Schema::hasColumn('ai_holding_enterprise_flow_operations_runbooks', 'operating_package_json')) {
                $table->json('operating_package_json')->default('{}')->after('operating_package_hash');
            }
            if (! Schema::hasColumn('ai_holding_enterprise_flow_operations_runbooks', 'replay_contract_json')) {
                $table->json('replay_contract_json')->default('{}')->after('operating_package_json');
            }
            if (! Schema::hasColumn('ai_holding_enterprise_flow_operations_runbooks', 'operating_package_attestations_json')) {
                $table->json('operating_package_attestations_json')->default('[]')->after('replay_contract_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_holding_enterprise_flow_operations_runbooks')) {
            return;
        }

        Schema::table('ai_holding_enterprise_flow_operations_runbooks', function (Blueprint $table): void {
            foreach ([
                'operating_package_attestations_json',
                'replay_contract_json',
                'operating_package_json',
                'operating_package_hash',
            ] as $column) {
                if (Schema::hasColumn('ai_holding_enterprise_flow_operations_runbooks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
