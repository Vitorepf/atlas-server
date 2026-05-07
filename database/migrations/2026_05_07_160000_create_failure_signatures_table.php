<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('failure_signatures')) {
            Schema::create('failure_signatures', function (Blueprint $table): void {
                $table->id();
                $table->string('envelope_id', 80);
                $table->string('source_ledger_event_id', 80)->nullable();
                $table->string('signature_key', 200);
                $table->string('domain', 64);
                $table->string('category', 64);
                $table->string('sub_cause', 128);
                $table->text('context_summary');
                $table->json('canonical_features')->nullable();
                $table->json('vector_embedding')->nullable();
                $table->decimal('similarity_to_previous', 4, 3)->nullable();
                $table->unsignedSmallInteger('recurrence_count')->default(1);
                $table->timestamp('recorded_at');
                $table->timestamps();
                $table->index(['domain', 'category', 'sub_cause']);
                $table->index(['signature_key', 'recorded_at']);
                $table->index('recorded_at');
            });
        }

        if (! Schema::hasTable('failure_diversity_metrics')) {
            Schema::create('failure_diversity_metrics', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 64);
                $table->timestamp('window_start');
                $table->timestamp('window_end');
                $table->unsignedInteger('total_failures')->default(0);
                $table->unsignedInteger('unique_signatures')->default(0);
                $table->decimal('diversity_index', 4, 3);
                $table->unsignedInteger('repetition_alerts_triggered')->default(0);
                $table->json('top_repeated_signatures')->nullable();
                $table->timestamp('computed_at');
                $table->timestamps();
                $table->index(['domain', 'window_end']);
            });
        }

        if (! Schema::hasTable('failure_repetition_alerts')) {
            Schema::create('failure_repetition_alerts', function (Blueprint $table): void {
                $table->id();
                $table->string('signature_key', 200);
                $table->string('domain', 64);
                $table->unsignedSmallInteger('repetition_count');
                $table->timestamp('first_occurrence_at');
                $table->timestamp('latest_occurrence_at');
                $table->string('alert_status', 32)->default('open');
                $table->string('severity', 32)->default('warning');
                $table->text('operator_reflection')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['signature_key', 'alert_status']);
                $table->index(['domain', 'alert_status']);
            });
        }

        if (Schema::hasTable('failure_signatures') && Schema::hasTable('failure_repetition_alerts')) {
            DB::table('failure_repetition_alerts')
                ->whereNull('severity')
                ->update(['severity' => 'warning']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
    }
};
