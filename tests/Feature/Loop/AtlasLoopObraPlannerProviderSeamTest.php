<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentSpecCompiler;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use Tests\TestCase;

/**
 * ACDE Leap 1 — PART A (truth-in-wiring) frozen ratchet for the PLANNER PROVIDER SEAM.
 *
 * The adapter's spec + DAG provider seam is REAL: maybePlan() runs the live
 * {@see AtlasLoopIntentSpecCompiler} +
 * {@see AtlasLoopObraDecompositionPlanner} against the
 * provider, and the generate*ViaProvider PARSE logic turns a recorded provider JSON transcript into a
 * READY plan with a create-class node at seq 0 (the executor walks seq ASCENDING, NOT depends_on) and
 * the net-new file folded into the allowed scope.
 *
 * The provider call is exercised WITHOUT a live call via a test double that overrides the protected
 * obraPlanningProviderRaw() seam to return a canned transcript — so the parse runs FOR REAL.
 *
 * Byte-identical-OFF: atlas.loop.planning_enabled OFF (default) => maybePlan returns null unchanged.
 */
final class AtlasLoopObraPlannerProviderSeamTest extends TestCase
{
    private function payload(): array
    {
        return [
            'objective' => 'Extract the god method in app/Hub.php into a new smaller helper class and redirect, preserving behaviour.',
            // objective_kind makes the task QUALIFY for planning (maybePlan gates on >=2 allowed_files OR a
            // refactor_/feature_ objective_kind); a single-file extract-class is the canonical refactor case.
            'objective_kind' => 'refactor_extract_class',
            'allowed_files' => ['app/Hub.php'],
            'target_relative_path' => 'app/Hub.php',
            'acceptance' => ['commands' => ['php -r "exit(0);"']],
        ];
    }

    public function test_planning_disabled_by_default_returns_null(): void
    {
        config()->set('atlas.loop.planning_enabled', false);

        $adapter = $this->adapterReturning(
            $this->specJson(),
            $this->dagJson(),
        );

        $this->assertNull(
            $adapter->maybePlan($this->payload(), ['app/Hub.php']),
            'planning_enabled OFF => maybePlan is a no-op (buildPlan fallback runs, byte-identical)',
        );
    }

    public function test_recorded_transcript_produces_a_ready_plan_with_create_class_node_at_seq_zero(): void
    {
        config()->set('atlas.loop.planning_enabled', true);

        $adapter = $this->adapterReturning(
            $this->specJson(),
            $this->dagJson(),
        );

        $result = $adapter->maybePlan($this->payload(), ['app/Hub.php']);

        $this->assertIsArray($result, 'a recorded spec+DAG transcript yields a READY provider plan');
        $plan = $result['plan'];
        $this->assertIsArray($plan);
        $this->assertNotSame('', trim((string) ($plan['plan_id'] ?? '')), 'the plan has an id');

        $nodes = array_values($plan['nodes']);
        $this->assertGreaterThanOrEqual(2, count($nodes), 'a decomposition is >=2 nodes');

        // The CREATE-CLASS node leads at seq 0 (the executor materializes the new class first).
        usort($nodes, static fn (array $a, array $b): int => ((int) $a['seq']) <=> ((int) $b['seq']));
        $first = $nodes[0];
        $this->assertSame(0, (int) $first['seq'], 'the create-class node is at seq 0');
        $this->assertSame('app/Support/HubHelper.php', (string) $first['target_area'], 'seq 0 creates the new file');
        $this->assertSame([], (array) $first['depends_on'], 'the create-class node depends on nothing');

        // A later edit/redirect node touches the EXISTING file and depends on the create node.
        $edit = $nodes[1];
        $this->assertGreaterThan(0, (int) $edit['seq'], 'the edit node runs after the create node');
        $this->assertSame('app/Hub.php', (string) $edit['target_area']);
        $this->assertContains((string) $first['id'], (array) $edit['depends_on'], 'the redirect depends on the create-class node');

        // The new file is folded into the allowed scope (so the validator/executor admit it).
        $this->assertContains('app/Support/HubHelper.php', $result['allowed'], 'the new file is in allowed scope');
        $this->assertContains('app/Hub.php', $result['allowed'], 'the original file stays in allowed scope');
    }

    public function test_provider_parse_failure_fails_open_to_null(): void
    {
        config()->set('atlas.loop.planning_enabled', true);

        // The provider returns garbage (no JSON object) => spec parse fails => compiler refuses => null.
        $adapter = $this->adapterReturning('not json at all', 'still not json');

        $this->assertNull(
            $adapter->maybePlan($this->payload(), ['app/Hub.php']),
            'a parse failure fails open => maybePlan null => buildPlan fallback => SAFE',
        );
    }

    /** A recorded spec-only JSON transcript (what the provider returns for the spec call). */
    private function specJson(): string
    {
        return json_encode([
            'summary' => 'Extract the worst method of app/Hub.php into a new HubHelper class and redirect to it.',
            'acceptance_criteria' => [
                ['id' => 'AC1', 'description' => 'Hub::run keeps its observable behaviour identical', 'required' => true],
                ['id' => 'AC2', 'description' => 'the extracted helper methods are each strictly simpler', 'required' => true],
            ],
            'suggested_files' => ['app/Support/HubHelper.php'],
            'decomposition_hint' => 'create HubHelper, then redirect Hub::run onto it',
        ], JSON_THROW_ON_ERROR);
    }

    /** A recorded DAG-only JSON transcript — lists only the EXISTING file to edit (Atlas emits the create node). */
    private function dagJson(): string
    {
        return json_encode([
            'plan_id' => 'obra-hub-extract',
            'nodes' => [
                ['id' => 'redirect-hub', 'target_area' => 'app/Hub.php', 'request' => 'Redirect app/Hub.php::run onto the extracted HubHelper and simplify it in place'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A test double that overrides the PROTECTED provider seam to return recorded transcripts
     * (spec mode => $spec, dag mode => $dag) so the generate*ViaProvider PARSE logic runs for real
     * with NO live provider call and NO spend.
     */
    private function adapterReturning(string $spec, string $dag): AtlasLoopObraExecutionAdapter
    {
        return new class($spec, $dag) extends AtlasLoopObraExecutionAdapter
        {
            public function __construct(private readonly string $spec, private readonly string $dag)
            {
                parent::__construct();
            }

            protected function obraPlanningProviderRaw(string $mode, string $prompt): string
            {
                return $mode === 'spec' ? $this->spec : $this->dag;
            }

            // Widen the protected planner entrypoint to public so the test can drive it directly.
            public function maybePlan(array $payload, array $allowed): ?array
            {
                return parent::maybePlan($payload, $allowed);
            }
        };
    }
}
