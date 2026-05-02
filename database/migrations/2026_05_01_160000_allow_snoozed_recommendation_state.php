<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('ai_performance_recommendations')) {
            return;
        }

        DB::statement('ALTER TABLE ai_performance_recommendations DROP CONSTRAINT IF EXISTS ai_performance_recommendations_state_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ai_performance_recommendations
            ADD CONSTRAINT ai_performance_recommendations_state_check
            CHECK (state IN ('proposed','acknowledged','in_progress','applied','measured','resolved','rejected','snoozed','expired','superseded','self_healed'))
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('ai_performance_recommendations')) {
            return;
        }

        DB::statement('ALTER TABLE ai_performance_recommendations DROP CONSTRAINT IF EXISTS ai_performance_recommendations_state_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ai_performance_recommendations
            ADD CONSTRAINT ai_performance_recommendations_state_check
            CHECK (state IN ('proposed','acknowledged','in_progress','applied','measured','resolved','rejected','expired','superseded','self_healed'))
        SQL);
    }
};
