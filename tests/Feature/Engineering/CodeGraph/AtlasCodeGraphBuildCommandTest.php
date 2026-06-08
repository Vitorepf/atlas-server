<?php

namespace Tests\Feature\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Focused test for the atlas:code-graph:build promotion command. The full
 * Postgres migration set can't run on the suite's sqlite :memory: connection
 * (raw DDL), so — following the proven sibling convention (CodeGraphEdgeBuilderTest /
 * WorldModelGraphRankerTest) — we boot only the one table the command's resolution
 * path touches. The populate path itself is covered by CodeGraphEdgeBuilderTest.
 */
class AtlasCodeGraphBuildCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_codebase_world_models', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('model_id')->nullable();
            $table->string('scope')->nullable();
            $table->string('status')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('receipt')->nullable();
            $table->string('model_hash')->nullable();
            $table->unsignedBigInteger('goal_record_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_codebase_world_models');

        parent::tearDown();
    }

    public function test_fails_gracefully_when_no_world_model_exists(): void
    {
        $this->artisan('atlas:code-graph:build')
            ->expectsOutputToContain('No world model found')
            ->assertExitCode(1);
    }

    public function test_reports_disabled_when_flag_off(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        AiCodebaseWorldModel::query()->create([
            'model_id' => 'm-test',
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => [],
            'risks' => [],
            'receipt' => [],
            'model_hash' => 'h',
        ]);

        $this->artisan('atlas:code-graph:build')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }
}
