<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        Schema::table('ai_trace_metric_summaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_trace_metric_summaries', 'cost_source')) {
                $table->string('cost_source', 48)->nullable()->after('cost_confidence');
            }

            if (! Schema::hasColumn('ai_trace_metric_summaries', 'cost_mode')) {
                $table->string('cost_mode', 48)->default('unknown')->after('cost_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        Schema::table('ai_trace_metric_summaries', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_trace_metric_summaries', 'cost_mode')) {
                $table->dropColumn('cost_mode');
            }

            if (Schema::hasColumn('ai_trace_metric_summaries', 'cost_source')) {
                $table->dropColumn('cost_source');
            }
        });
    }
};
