<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_performance_report_runs')) {
            return;
        }

        Schema::table('ai_performance_report_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_performance_report_runs', 'input_snapshot')) {
                $table->json('input_snapshot')->nullable()->after('input_hash');
            }
            if (! Schema::hasColumn('ai_performance_report_runs', 'output_hash')) {
                $table->string('output_hash', 64)->nullable()->after('input_snapshot');
            }
            if (! Schema::hasColumn('ai_performance_report_runs', 'output_snapshot')) {
                $table->json('output_snapshot')->nullable()->after('output_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_performance_report_runs')) {
            return;
        }

        Schema::table('ai_performance_report_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_performance_report_runs', 'output_snapshot')) {
                $table->dropColumn('output_snapshot');
            }
            if (Schema::hasColumn('ai_performance_report_runs', 'output_hash')) {
                $table->dropColumn('output_hash');
            }
            if (Schema::hasColumn('ai_performance_report_runs', 'input_snapshot')) {
                $table->dropColumn('input_snapshot');
            }
        });
    }
};
