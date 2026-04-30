<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE semantic_note_activations
                DROP CONSTRAINT IF EXISTS semantic_note_activations_context_type_check;
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE semantic_note_activations
                ADD CONSTRAINT semantic_note_activations_context_type_check
                CHECK (context_type IN (
                    'morning_briefing',
                    'capture_created',
                    'health_state',
                    'rize_context',
                    'weekly_review',
                    'manual_search',
                    'cognitive_game',
                    'notification_candidate',
                    'project_context',
                    'domain_context',
                    'decision_context'
                ));
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE semantic_note_activations
                ADD COLUMN IF NOT EXISTS feedback_action TEXT
                CHECK (feedback_action IS NULL OR feedback_action IN (
                    'useful',
                    'not_useful',
                    'too_early',
                    'too_late',
                    'dismissed'
                ));
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE semantic_note_activations DROP COLUMN IF EXISTS feedback_action;');

            DB::statement(<<<'SQL'
                ALTER TABLE semantic_note_activations
                DROP CONSTRAINT IF EXISTS semantic_note_activations_context_type_check;
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE semantic_note_activations
                ADD CONSTRAINT semantic_note_activations_context_type_check
                CHECK (context_type IN (
                    'morning_briefing',
                    'capture_created',
                    'health_state',
                    'rize_context',
                    'weekly_review',
                    'manual_search',
                    'cognitive_game',
                    'notification_candidate'
                ));
            SQL);
        });
    }
};
