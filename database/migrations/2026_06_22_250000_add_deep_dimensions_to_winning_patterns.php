<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'economics_real',
        'keyword_performance',
        'device_split',
        'network_split',
        'match_type_split',
        'geo',
        'timing',
        'funnel_profile',
        'bidding_of_winners',
        'winner_commonalities',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_winning_patterns')) {
            return;
        }

        Schema::table('ai_marketing_winning_patterns', function (Blueprint $t): void {
            foreach (self::COLUMNS as $c) {
                if (! Schema::hasColumn('ai_marketing_winning_patterns', $c)) {
                    $t->json($c)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_marketing_winning_patterns')) {
            return;
        }

        Schema::table('ai_marketing_winning_patterns', function (Blueprint $t): void {
            foreach (self::COLUMNS as $c) {
                if (Schema::hasColumn('ai_marketing_winning_patterns', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
