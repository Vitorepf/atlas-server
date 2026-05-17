<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_router_decisions')) {
            return;
        }

        Schema::table('ai_router_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_router_decisions', 'schema_version')) {
                $table->string('schema_version', 64)->nullable()->after('trace_id');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'surface_id')) {
                $table->string('surface_id', 80)->nullable()->after('schema_version');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'flow_id')) {
                $table->string('flow_id', 80)->nullable()->after('surface_id');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'flow_origin')) {
                $table->string('flow_origin', 40)->nullable()->after('flow_id');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'command_intent')) {
                $table->string('command_intent', 80)->nullable()->after('flow_origin');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'routing_reason')) {
                $table->string('routing_reason', 160)->nullable()->after('command_intent');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'routing_confidence')) {
                $table->string('routing_confidence', 24)->nullable()->after('routing_reason');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'workspace_present')) {
                $table->boolean('workspace_present')->default(false)->after('routing_confidence');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'handoff_payload')) {
                $table->json('handoff_payload')->nullable()->after('signals');
            }
            if (! Schema::hasColumn('ai_router_decisions', 'alternative_flow_ids')) {
                $table->json('alternative_flow_ids')->nullable()->after('handoff_payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_router_decisions')) {
            return;
        }

        Schema::table('ai_router_decisions', function (Blueprint $table): void {
            foreach ([
                'alternative_flow_ids',
                'handoff_payload',
                'workspace_present',
                'routing_confidence',
                'routing_reason',
                'command_intent',
                'flow_origin',
                'flow_id',
                'surface_id',
                'schema_version',
            ] as $column) {
                if (Schema::hasColumn('ai_router_decisions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
