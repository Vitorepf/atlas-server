<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_tool_definitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_tool_definitions', 'execution_tier')) {
                $table->string('execution_tier', 24)->default('T1')->index()->after('status');
            }

            if (! Schema::hasColumn('atlas_tool_definitions', 'expected_cost')) {
                $table->string('expected_cost', 40)->default('local_fast')->index()->after('execution_tier');
            }

            if (! Schema::hasColumn('atlas_tool_definitions', 'default_trigger')) {
                $table->string('default_trigger', 80)->default('manual_or_policy')->index()->after('expected_cost');
            }

            if (! Schema::hasColumn('atlas_tool_definitions', 'authority_role')) {
                $table->string('authority_role', 40)->default('primary')->index()->after('default_trigger');
            }

            if (! Schema::hasColumn('atlas_tool_definitions', 'authority_group')) {
                $table->string('authority_group', 80)->nullable()->index()->after('authority_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_tool_definitions', function (Blueprint $table): void {
            foreach ([
                'authority_group',
                'authority_role',
                'default_trigger',
                'expected_cost',
                'execution_tier',
            ] as $column) {
                if (Schema::hasColumn('atlas_tool_definitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
