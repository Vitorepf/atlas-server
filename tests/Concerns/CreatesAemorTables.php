<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAemorTables
{
    protected function createAemorTables(bool $withMemoryDeltas = true): void
    {
        $this->dropAemorTables();

        Schema::create('atlas_aemor_execution_episodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('scope_type', 80)->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->string('workspace')->nullable()->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('provider', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->uuid('obra_id')->nullable()->index();
            $table->uuid('apcr_pack_id')->nullable()->index();
            $table->string('persistent_context_hash', 64)->nullable()->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->json('workspace_baseline')->nullable();
            $table->json('risk_prediction')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('episode_hash', 64)->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_aemor_execution_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->index();
            $table->string('schema_version', 120);
            $table->string('event_type', 80)->index();
            $table->string('stage', 120)->nullable()->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->json('evidence_refs')->nullable();
            $table->uuid('causation_event_id')->nullable()->index();
            $table->string('payload_hash', 64)->index();
            $table->string('event_hash', 64)->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_aemor_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('outcome_type', 80)->index();
            $table->string('failure_signature', 120)->nullable()->index();
            $table->text('summary');
            $table->json('metrics')->nullable();
            $table->json('blockers')->nullable();
            $table->json('evidence_refs');
            $table->json('context_utility')->nullable();
            $table->json('patch_outcome')->nullable();
            $table->json('claim_policy')->nullable();
            $table->string('outcome_hash', 64)->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_aemor_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->index();
            $table->uuid('outcome_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('signal_type', 80)->index();
            $table->string('status', 40)->index();
            $table->text('claim');
            $table->float('confidence')->default(0.5);
            $table->string('scope_type', 80)->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->json('evidence_refs');
            $table->json('metadata')->nullable();
            $table->string('signal_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aemor_memory_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->index();
            $table->uuid('outcome_id')->nullable()->index();
            $table->uuid('learning_signal_id')->nullable()->index();
            $table->uuid('memory_delta_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('memory_type', 80)->index();
            $table->text('claim');
            $table->float('confidence')->default(0.5);
            $table->string('scope_type', 80)->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->json('promotion_gate');
            $table->json('evidence_refs');
            $table->json('metadata')->nullable();
            $table->string('candidate_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aemor_judgment_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->index();
            $table->uuid('outcome_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->json('causality_rank');
            $table->json('outcome_attribution')->nullable();
            $table->json('false_learning_gate');
            $table->json('repeated_failure_suppression');
            $table->json('context_roi_score');
            $table->json('patch_quality_fingerprint')->nullable();
            $table->json('memory_conflicts')->nullable();
            $table->json('human_correction')->nullable();
            $table->json('negative_knowledge')->nullable();
            $table->json('provider_skill_reliability')->nullable();
            $table->json('counterfactual_replay')->nullable();
            $table->json('memory_budget')->nullable();
            $table->json('operational_doctrine')->nullable();
            $table->json('risk_prediction')->nullable();
            $table->json('policy_proposals')->nullable();
            $table->json('quality_score');
            $table->json('evidence_refs');
            $table->string('judgment_hash', 64)->index();
            $table->timestamps();
        });

        if ($withMemoryDeltas) {
            Schema::create('ai_memory_deltas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('source_trace_id')->nullable()->index();
                $table->uuid('source_session_id')->nullable()->index();
                $table->string('source_workspace')->nullable();
                $table->string('type', 32)->default('process');
                $table->text('claim');
                $table->json('evidence');
                $table->string('scope', 255)->default('global');
                $table->float('confidence')->default(0.5);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->json('use_when')->nullable();
                $table->json('do_not_use_when')->nullable();
                $table->boolean('requires_confirmation')->default(true);
                $table->string('status', 16)->default('pending');
                $table->uuid('superseded_by')->nullable();
                $table->uuid('promoted_memory_entry_id')->nullable();
                $table->timestamp('promoted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function dropAemorTables(): void
    {
        foreach ([
            'ai_memory_deltas',
            'atlas_aemor_judgment_reports',
            'atlas_aemor_memory_candidates',
            'atlas_aemor_learning_signals',
            'atlas_aemor_outcomes',
            'atlas_aemor_execution_events',
            'atlas_aemor_execution_episodes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
