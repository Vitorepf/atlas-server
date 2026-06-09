<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pattern-detection ledger (idempotency + audit for the two operator-intelligence
 * bridges) + the proactive-origin columns on ai_missions. Patterns are DERIVED (the
 * canonical truth stays in operator_learning_signals / operator_profile_items); this
 * table only records "this recurrence was detected + what we proposed for it", keyed
 * unique on (operator_id, pattern_id) so the same recurrence is never re-proposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operator_pattern_detections')) {
            Schema::create('operator_pattern_detections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('operator_id', 120)->index();
                $table->string('pattern_id', 80);
                $table->string('kind', 40); // repeated_action | temporal_cadence | action_sequence
                $table->string('taxonomy_item_id', 16)->nullable();
                $table->text('summary');
                $table->string('signature', 255);
                $table->unsignedInteger('occurrence_count')->default(0);
                $table->unsignedSmallInteger('window_days')->default(28);
                $table->float('confidence')->default(0.0);
                $table->string('privacy_class', 20)->default('normal');
                $table->string('proposal_target', 20)->default('mission'); // skill | mission | both
                $table->json('cadence')->nullable();
                $table->json('evidence');
                $table->string('status', 24)->default('detected'); // detected | proposed | dismissed | promoted
                $table->string('proposed_skill_task_id')->nullable();
                $table->string('proposed_mission_id')->nullable();
                $table->timestamps();

                $table->unique(['operator_id', 'pattern_id']);
                $table->index(['operator_id', 'status']);
            });
        }

        if (Schema::hasTable('ai_missions') && ! Schema::hasColumn('ai_missions', 'proactive_origin')) {
            Schema::table('ai_missions', function (Blueprint $table): void {
                $table->json('proactive_origin')->nullable()->after('metadata');
                $table->string('proactive_status', 24)->nullable()->after('proactive_origin'); // proposed | accepted | dismissed
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_pattern_detections');
        if (Schema::hasTable('ai_missions') && Schema::hasColumn('ai_missions', 'proactive_origin')) {
            Schema::table('ai_missions', function (Blueprint $table): void {
                $table->dropColumn(['proactive_origin', 'proactive_status']);
            });
        }
    }
};
