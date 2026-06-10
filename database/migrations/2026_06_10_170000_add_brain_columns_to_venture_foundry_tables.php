<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_venture_ideas') && ! Schema::hasColumn('ai_venture_ideas', 'generation_meta')) {
            Schema::table('ai_venture_ideas', function (Blueprint $table): void {
                $table->json('generation_meta')->nullable();
            });
        }

        if (Schema::hasTable('ai_venture_strategy_reviews')) {
            Schema::table('ai_venture_strategy_reviews', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_venture_strategy_reviews', 'trajectory')) {
                    $table->json('trajectory')->nullable();
                }
                if (! Schema::hasColumn('ai_venture_strategy_reviews', 'bridged_mission_ids')) {
                    $table->json('bridged_mission_ids')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_venture_ideas') && Schema::hasColumn('ai_venture_ideas', 'generation_meta')) {
            Schema::table('ai_venture_ideas', function (Blueprint $table): void {
                $table->dropColumn('generation_meta');
            });
        }

        if (Schema::hasTable('ai_venture_strategy_reviews')) {
            Schema::table('ai_venture_strategy_reviews', function (Blueprint $table): void {
                if (Schema::hasColumn('ai_venture_strategy_reviews', 'trajectory')) {
                    $table->dropColumn('trajectory');
                }
                if (Schema::hasColumn('ai_venture_strategy_reviews', 'bridged_mission_ids')) {
                    $table->dropColumn('bridged_mission_ids');
                }
            });
        }
    }
};
