<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesIntelligenceFactoryTables
{
    protected function createIntelligenceFactoryTables(): void
    {
        $this->dropIntelligenceFactoryTables();

        Schema::create('atlas_intelligence_factory_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('capability_key', 180)->unique();
            $table->string('name');
            $table->string('capability_type', 80)->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('version', 40)->default('v1');
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->json('safety_policy')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('certification_hash', 64)->nullable()->index();
            $table->json('aemor_outcome_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('capability_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_intelligence_factory_gaps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('scope_type', 80)->nullable()->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->string('gap_type', 80)->index();
            $table->string('severity', 40)->index();
            $table->json('missing_capabilities');
            $table->json('existing_candidates')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('gap_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_intelligence_factory_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gap_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('objective_hash', 64)->index();
            $table->string('decision', 40)->index();
            $table->string('status', 40)->index();
            $table->json('rationale');
            $table->uuid('selected_capability_id')->nullable()->index();
            $table->json('required_controls');
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_intelligence_factory_simulations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('decision_id')->nullable()->index();
            $table->uuid('capability_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('mode', 40)->index();
            $table->text('scenario');
            $table->json('predicted_actions');
            $table->json('risks')->nullable();
            $table->json('required_evidence')->nullable();
            $table->json('result')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('simulation_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_intelligence_factory_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capability_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->json('checks');
            $table->json('evidence_refs')->nullable();
            $table->string('certification_hash', 64)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_intelligence_factory_evolution_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capability_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 180)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->json('evidence_refs')->nullable();
            $table->string('event_hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropIntelligenceFactoryTables(): void
    {
        foreach ([
            'atlas_intelligence_factory_evolution_events',
            'atlas_intelligence_factory_certifications',
            'atlas_intelligence_factory_simulations',
            'atlas_intelligence_factory_decisions',
            'atlas_intelligence_factory_gaps',
            'atlas_intelligence_factory_capabilities',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
