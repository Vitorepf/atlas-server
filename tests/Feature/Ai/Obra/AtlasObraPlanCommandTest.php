<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AOBG N3.F1 — `atlas:obra:plan` renders the plan-DAG (PLAN ONLY, cost-free).
 *
 * Drives the real command end to end over the real tables (sqlite) with the default
 * deterministic decomposer — ZERO provider spend. The command is plan-only: it never
 * touches git, never makes a branch, never merges.
 */
final class AtlasObraPlanCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
        config()->set('atlas.obra.enabled', true);
        config()->set('atlas.obra.decompose_provider', ''); // deterministic = cost-free
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
        parent::tearDown();
    }

    public function test_renders_the_dag_tree(): void
    {
        $this->artisan('atlas:obra:plan', [
            'intent' => 'add a Foo service; then wire Foo into Bar; then test it',
            '--workspace' => 'atlas-server',
        ])
            ->expectsOutputToContain('decomposer=deterministic')  // header line
            ->expectsOutputToContain('add a Foo service')          // a node request line
            ->expectsOutputToContain('Plan only — no execution')   // the closing comment
            ->assertExitCode(0);

        // The DAG was persisted with all three nodes (the render is plan-only).
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('atlas_obra_nodes')->count());
    }

    public function test_json_output_carries_the_full_plan(): void
    {
        $this->artisan('atlas:obra:plan', [
            'intent' => 'alpha; beta',
            '--json' => true,
        ])->assertExitCode(0);

        // The plan was persisted (json mode still plans + persists).
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('atlas_obra_plans')->count());
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('atlas_obra_nodes')->count());
    }

    public function test_empty_intent_is_invalid(): void
    {
        $this->artisan('atlas:obra:plan', ['intent' => '   '])
            ->assertExitCode(2); // self::INVALID
    }

    public function test_over_cap_plan_is_refused_with_a_failure_exit(): void
    {
        $this->artisan('atlas:obra:plan', [
            'intent' => 'one; two; three; four',
            '--max' => '2',
        ])
            ->expectsOutputToContain('obra plan refused')
            ->assertExitCode(1);
    }
}
