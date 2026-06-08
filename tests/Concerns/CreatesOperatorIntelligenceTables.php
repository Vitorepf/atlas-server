<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesOperatorIntelligenceTables
{
    protected function createOperatorIntelligenceTables(): void
    {
        $this->dropOperatorIntelligenceTables();

        Schema::create('operator_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id');
            $table->string('taxonomy_item_id');
            $table->string('signal_kind');
            $table->string('source_type');
            $table->string('source_ref_type')->nullable();
            $table->string('source_ref_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('raw_excerpt_hash')->nullable();
            $table->text('normalized_claim');
            $table->json('evidence_refs')->nullable();
            $table->string('privacy_class')->default('normal');
            $table->string('risk_level')->default('low');
            $table->float('confidence')->default(0.5);
            $table->string('scope_type')->default('global');
            $table->string('scope_id')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('signal_id')->nullable();
            $table->string('operator_id');
            $table->string('taxonomy_item_id');
            $table->text('claim');
            $table->json('value')->nullable();
            $table->string('status')->default('candidate');
            $table->float('confidence')->default(0.5);
            $table->string('conflict_group')->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->boolean('auto_apply_eligible')->default(false);
            $table->json('gate_receipt')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_profile_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id');
            $table->string('taxonomy_item_id');
            $table->string('profile_key');
            $table->json('value')->nullable();
            $table->text('summary');
            $table->string('scope_type')->default('global');
            $table->string('scope_id')->nullable();
            $table->string('validity_kind')->default('permanent');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->float('confidence')->default(0.5);
            $table->string('privacy_class')->default('normal');
            $table->string('automation_level')->default('observe');
            $table->string('status')->default('active');
            $table->uuid('source_candidate_id')->nullable();
            $table->uuid('source_memory_entry_id')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_profile_policy_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operator_profile_item_id');
            $table->string('rule_key');
            $table->json('rule');
            $table->string('applies_to_flow')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('effect');
            $table->string('reverse_handle')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_profile_feedback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operator_profile_item_id');
            $table->string('trace_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('feedback_action');
            $table->float('feedback_score')->nullable();
            $table->json('outcome')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('operator_profile_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id');
            $table->string('snapshot_kind');
            $table->longText('summary');
            $table->string('profile_hash');
            $table->json('included_item_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function dropOperatorIntelligenceTables(): void
    {
        foreach ([
            'operator_profile_snapshots',
            'operator_profile_feedback_events',
            'operator_profile_policy_rules',
            'operator_profile_items',
            'operator_learning_candidates',
            'operator_learning_signals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
