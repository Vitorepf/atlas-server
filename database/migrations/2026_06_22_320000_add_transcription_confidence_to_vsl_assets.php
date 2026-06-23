<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auditable transcription-fidelity score (0-100, mean decoder token confidence). First-class so a
 * VSL asset can be filtered/sorted by how reliable its transcript is. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }
        Schema::table('ai_marketing_vsl_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'transcription_confidence')) {
                $table->unsignedTinyInteger('transcription_confidence')->nullable()->after('transcription_engine');
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'transcription_coverage_pct')) {
                $table->decimal('transcription_coverage_pct', 5, 2)->nullable()->after('transcription_confidence');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }
        Schema::table('ai_marketing_vsl_assets', function (Blueprint $table): void {
            foreach (['transcription_confidence', 'transcription_coverage_pct'] as $col) {
                if (Schema::hasColumn('ai_marketing_vsl_assets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
