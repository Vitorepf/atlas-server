<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table): void {
            $table->string('context_strategy', 64)->nullable()->after('risk_level');
            $table->string('execution_strategy', 64)->nullable()->after('context_strategy');
            $table->json('task_profile')->nullable()->after('metrics_snapshot');
            $table->json('execution_graph')->nullable()->after('task_profile');

            $table->index(['context_strategy', 'created_at'], 'ai_decisions_context_strategy_created_at_index');
            $table->index(['execution_strategy', 'created_at'], 'ai_decisions_execution_strategy_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table): void {
            $table->dropIndex('ai_decisions_context_strategy_created_at_index');
            $table->dropIndex('ai_decisions_execution_strategy_created_at_index');
            $table->dropColumn([
                'context_strategy',
                'execution_strategy',
                'task_profile',
                'execution_graph',
            ]);
        });
    }
};
