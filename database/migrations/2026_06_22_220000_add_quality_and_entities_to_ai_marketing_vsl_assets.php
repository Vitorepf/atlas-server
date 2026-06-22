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
            foreach (['entities', 'structure_quality'] as $column) {
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
            foreach (['entities', 'structure_quality'] as $column) {
                if (Schema::hasColumn('ai_marketing_vsl_assets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
