<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAgenticWorkcellTables
{
    protected function createAgenticWorkcellTables(): void
    {
        $this->dropAgenticWorkcellTables();

        Schema::create('atlas_agentic_workcells', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('surface_id', 100)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('topology', 80)->index();
            $table->string('maturity_level', 80)->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->json('areg_decision')->nullable();
            $table->json('org_design');
            $table->json('role_roster');
            $table->json('task_graph');
            $table->json('context_packs');
            $table->json('execution_schedule');
            $table->json('verification_plan');
            $table->json('evidence_ledger');
            $table->json('memory_packet');
            $table->json('counterfactual_replay');
            $table->json('learning_policy');
            $table->json('control_plane_summary')->nullable();
            $table->json('claim_policy');
            $table->string('workcell_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_agentic_workcell_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workcell_id')->index();
            $table->string('schema_version', 120);
            $table->string('event_type', 80)->index();
            $table->string('status', 40)->index();
            $table->json('payload')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('event_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_agentic_workcell_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workcell_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->decimal('coordination_roi_score', 5, 2)->nullable();
            $table->json('signals')->nullable();
            $table->json('learning_candidates')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('outcome_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_agentic_workcell_org_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('topology', 80)->index();
            $table->json('pattern');
            $table->json('quality_stats')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('pattern_hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropAgenticWorkcellTables(): void
    {
        Schema::dropIfExists('atlas_agentic_workcell_org_patterns');
        Schema::dropIfExists('atlas_agentic_workcell_outcomes');
        Schema::dropIfExists('atlas_agentic_workcell_events');
        Schema::dropIfExists('atlas_agentic_workcells');
    }
}
