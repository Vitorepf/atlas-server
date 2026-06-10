<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_venture_strategy_reviews') && ! Schema::hasColumn('ai_venture_strategy_reviews', 'analysis')) {
            Schema::table('ai_venture_strategy_reviews', function (Blueprint $table): void {
                $table->json('analysis')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_venture_strategy_reviews') && Schema::hasColumn('ai_venture_strategy_reviews', 'analysis')) {
            Schema::table('ai_venture_strategy_reviews', function (Blueprint $table): void {
                $table->dropColumn('analysis');
            });
        }
    }
};
