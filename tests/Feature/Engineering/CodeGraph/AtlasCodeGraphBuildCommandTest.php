<?php

namespace Tests\Feature\Engineering\CodeGraph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test for the atlas:code-graph:build command's auto-seed behaviour. The full
 * Postgres migration set can't run on the suite's sqlite :memory: connection, so
 * — following the proven sibling convention (CodeGraphEdgeBuilderTest) — we boot
 * only the tables the command touches. The edge-population path itself is covered
 * by CodeGraphEdgeBuilderTest; here we prove the standalone seed + gating.
 */
class AtlasCodeGraphBuildCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_codebase_world_models', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('goal_record_id')->nullable();
            $table->string('schema_version')->nullable();
            $table->string('model_id')->nullable();
            $table->string('scope')->nullable();
            $table->string('status')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('receipt')->nullable();
            $table->string('model_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_codebase_world_model_nodes', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('world_model_id');
            $table->string('node_id');
            $table->string('node_type');
            $table->string('path')->nullable();
            $table->string('flow_id')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_modules', function ($table): void {
            $table->id();
            $table->string('slug');
            $table->string('root_path')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_code_modules');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');

        parent::tearDown();
    }

    public function test_fails_when_there_are_no_modules_to_seed(): void
    {
        // No world model AND no indexed modules -> seeding has nothing to build.
        $this->artisan('atlas:code-graph:build')
            ->expectsOutputToContain('Could not seed')
            ->assertExitCode(1);
    }

    public function test_auto_seeds_world_model_then_reports_disabled_when_flag_off(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        DB::table('atlas_engineering_code_modules')->insert([
            'slug' => 'ai-dev',
            'root_path' => 'app/Services/Ai/Dev',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('atlas:code-graph:build')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        // The command auto-seeded a world model + a module node from the index.
        $this->assertDatabaseHas('ai_codebase_world_model_nodes', [
            'node_id' => 'node:app/Services/Ai/Dev',
            'node_type' => 'module',
        ]);
    }
}
