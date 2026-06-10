<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AOBG N3.F4 — `atlas:obra:status`: the cost-free READ side of the operator surface.
 *
 * Locks the command over a persisted obra fixture (the F1 spine tables on sqlite —
 * NO git, NO provider): it reports the plan-DAG + per-node status + the one branch,
 * and degrades honestly on an unknown id. Listing with no --obra shows recent obras.
 */
final class AtlasObraStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
        parent::tearDown();
    }

    public function test_status_reports_the_plan_dag_and_per_node_status(): void
    {
        $this->seedObra('obra-stat', 'done', [
            ['n0', 0, 'done', 'atlas/obra/obra-stat', 'app/One.php'],
            ['n1', 1, 'done', 'atlas/obra/obra-stat', 'app/Two.php'],
        ]);

        // JSON path: the full machine envelope (the load-bearing assertion).
        $this->artisan('atlas:obra:status', ['--obra' => 'obra-stat', '--json' => true])
            ->assertExitCode(0);

        // Human path: the plan-DAG + the one branch are rendered (one substring per
        // distinct output line — Laravel matches expectsOutputToContain per line).
        $this->artisan('atlas:obra:status', ['--obra' => 'obra-stat'])
            ->expectsOutputToContain('obra=obra-stat  status=done')
            ->expectsOutputToContain('branch: atlas/obra/obra-stat')
            ->expectsOutputToContain('(obra-stat:n0)')
            ->assertExitCode(0);
    }

    public function test_unknown_obra_is_an_honest_not_found(): void
    {
        $this->artisan('atlas:obra:status', ['--obra' => 'obra-nope'])
            ->expectsOutputToContain('obra not found: obra-nope')
            ->assertExitCode(1);
    }

    public function test_no_id_lists_recent_obras(): void
    {
        $this->seedObra('obra-a', 'done', [['n0', 0, 'done', 'atlas/obra/obra-a', 'app/A.php']]);
        $this->seedObra('obra-b', 'needs_review', [['n0', 0, 'done', 'atlas/obra/obra-b', 'app/B.php']]);

        $this->artisan('atlas:obra:status')
            ->expectsOutputToContain('recent obras')
            ->expectsOutputToContain('obra-a')
            ->expectsOutputToContain('obra-b')
            ->assertExitCode(0);
    }

    public function test_empty_lists_nothing_to_show(): void
    {
        $this->artisan('atlas:obra:status')
            ->expectsOutputToContain('No obras yet')
            ->assertExitCode(0);
    }

    /**
     * @param  list<array{0:string,1:int,2:string,3:string,4:string}>  $nodes  [suffix, seq, status, branch, file]
     */
    private function seedObra(string $planId, string $status, array $nodes): void
    {
        DB::table('atlas_obra_plans')->insert([
            'id' => $planId,
            'intent' => 'intent for '.$planId,
            'workspace_id' => 'atlas-server',
            'status' => $status,
            'meta' => json_encode(['decomposer' => 'deterministic']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($nodes as [$suffix, $seq, $nodeStatus, $branch, $file]) {
            DB::table('atlas_obra_nodes')->insert([
                'id' => $planId.':'.$suffix,
                'plan_id' => $planId,
                'seq' => $seq,
                'title' => 'step '.($seq + 1),
                'request' => 'create '.$file,
                'target_area' => $file,
                'depends_on' => $seq === 0 ? '[]' : json_encode([$planId.':n'.($seq - 1)]),
                'status' => $nodeStatus,
                'brain_refs' => '[]',
                'result' => json_encode(['branch' => $branch, 'files_changed' => [$file], 'commit' => str_repeat('c', 40)]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
