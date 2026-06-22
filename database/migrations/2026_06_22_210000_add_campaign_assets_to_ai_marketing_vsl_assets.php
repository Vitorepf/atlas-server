<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        $table = 'ai_marketing_vsl_assets';
        Schema::table($table, function (Blueprint $t) use ($table): void {
            if (! Schema::hasColumn($table, 'mechanism_name')) {
                $t->string('mechanism_name', 300)->nullable();
            }
            if (! Schema::hasColumn($table, 'target_geo')) {
                $t->string('target_geo', 120)->nullable();
            }
            foreach ([
                'value_equation',
                'keywords',
                'advertorial_brief',
                'ad_assets',
                'cta',
                'objection_rebuttals',
                'power_phrases',
                'economics',
                'beat_timestamps',
                'top_terms',
            ] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $t->json($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        Schema::table('ai_marketing_vsl_assets', function (Blueprint $t): void {
            foreach ([
                'mechanism_name', 'target_geo', 'value_equation', 'keywords',
                'advertorial_brief', 'ad_assets', 'cta', 'objection_rebuttals',
                'power_phrases', 'economics', 'beat_timestamps', 'top_terms',
            ] as $column) {
                if (Schema::hasColumn('ai_marketing_vsl_assets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
