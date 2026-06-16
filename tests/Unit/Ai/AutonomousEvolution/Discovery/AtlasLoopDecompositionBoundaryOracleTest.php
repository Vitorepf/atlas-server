<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionBoundaryOracle;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 2 — the human-frozen decomposition boundary-oracle. The structural validator proves a DAG is
 * WELL-FORMED; this proves it is CORRECT against a HUMAN-frozen required-boundary set. A plausible-but-wrong
 * split that drops a required seam is REFUSED with 'decomposition_missing_required_boundary:<seam>'; a DAG
 * that supersets the frozen set passes. The bar is named by a human, never declared by the model — the moat.
 */
final class AtlasLoopDecompositionBoundaryOracleTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-oracle-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    private function node(string $id, string $file): array
    {
        return ['id' => $id, 'target_area' => $file, 'request' => 'edit '.$file, 'complexity_proof' => true];
    }

    public function test_a_dag_missing_a_required_boundary_is_refused_with_the_seam_reason(): void
    {
        $validator = new AtlasLoopObraPlanValidator;
        // The oracle requires two seams as SEPARATE nodes; the plan merges them into one node (only Foo.php).
        $oracle = [
            'required_boundaries' => ['app/Foo.php', 'app/Support/FooHelper.php'],
            'required_create_files' => ['app/Support/FooHelper.php'],
        ];
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('n1', 'app/Foo.php'),
            $this->node('n2', 'app/Bar.php'), // wrong seam — the required helper boundary is absent
        ]];

        $missing = $validator->assertDecompositionMatchesOracle($plan, $oracle);

        $this->assertContains('decomposition_missing_required_boundary:app/Support/FooHelper.php', $missing);
        $this->assertNotContains('decomposition_missing_required_boundary:app/Foo.php', $missing, 'Foo.php IS a node target, so it is not missing');
    }

    public function test_a_complete_dag_that_supersets_the_oracle_returns_empty(): void
    {
        $validator = new AtlasLoopObraPlanValidator;
        $oracle = [
            'required_boundaries' => ['app/Foo.php', 'app/Support/FooHelper.php'],
            'required_create_files' => ['app/Support/FooHelper.php'],
        ];
        // The correct split: a create-class node for the helper + a redirect node for Foo (+ extra nodes ok).
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('create', 'app/Support/FooHelper.php'),
            $this->node('redirect', 'app/Foo.php'),
            $this->node('extra', 'app/Baz.php'), // superset is fine — only MISSING required seams refuse
        ]];

        $this->assertSame([], $validator->assertDecompositionMatchesOracle($plan, $oracle));
    }

    public function test_an_empty_oracle_never_false_rejects(): void
    {
        $validator = new AtlasLoopObraPlanValidator;
        $plan = ['plan_id' => 'p', 'nodes' => [$this->node('n1', 'app/Foo.php')]];

        $this->assertSame([], $validator->assertDecompositionMatchesOracle($plan, []));
        $this->assertSame([], $validator->assertDecompositionMatchesOracle($plan, ['required_boundaries' => [], 'required_create_files' => []]));
    }

    public function test_allowed_files_on_a_node_count_toward_the_generated_boundary_set(): void
    {
        $validator = new AtlasLoopObraPlanValidator;
        $oracle = ['required_boundaries' => ['app/Foo.php', 'app/Bar.php']];
        // A node may carry its seams in allowed_files rather than target_area — both count.
        $plan = ['plan_id' => 'p', 'nodes' => [
            ['id' => 'n1', 'allowed_files' => ['app/Foo.php', 'app/Bar.php'], 'request' => 'x', 'complexity_proof' => true],
            $this->node('n2', 'app/Baz.php'),
        ]];

        $this->assertSame([], $validator->assertDecompositionMatchesOracle($plan, $oracle));
    }

    public function test_goal_hash_is_deterministic_and_normalizes_case_and_whitespace(): void
    {
        $reader = new AtlasLoopDecompositionBoundaryOracle($this->dir);

        $a = $reader->goalHash('Extract FooHelper from Foo');
        $b = $reader->goalHash('  extract foohelper from foo  ');

        $this->assertSame($a, $b, 'normalized objective => same hash (trim + lowercase)');
        $this->assertSame(16, strlen($a));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
    }

    public function test_reader_loads_a_frozen_fixture_and_reports_absence(): void
    {
        $reader = new AtlasLoopDecompositionBoundaryOracle($this->dir);
        $goal = 'Extract FooHelper from Foo';

        $this->assertFalse($reader->hasOracle($goal));
        $this->assertNull($reader->load($goal));

        file_put_contents($reader->fixturePath($goal), json_encode([
            'required_boundaries' => ['/app/Foo.php', 'app/Support/FooHelper.php'],
            'required_create_files' => ['app/Support/FooHelper.php'],
        ]));

        $this->assertTrue($reader->hasOracle($goal));
        $loaded = $reader->load($goal);
        $this->assertNotNull($loaded);
        // Leading slash is normalized away so seam paths match the validator's ltrim'd targets.
        $this->assertContains('app/Foo.php', $loaded['required_boundaries']);
        $this->assertContains('app/Support/FooHelper.php', $loaded['required_create_files']);
    }

    public function test_reader_returns_null_for_malformed_fixture_no_false_reject(): void
    {
        $reader = new AtlasLoopDecompositionBoundaryOracle($this->dir);
        $goal = 'broken';
        file_put_contents($reader->fixturePath($goal), 'not json {{{');

        // A malformed fixture must degrade to "no oracle" (null), never throw / never false-reject.
        $this->assertNull($reader->load($goal));
    }
}
