<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmTopologyAutoComposerService;
use App\Services\Ai\AtlasDecide\AtlasSwarmTopologySelector;
use Tests\TestCase;

/**
 * L6-10 FROZEN SERVICE test — the topology auto-composer is out of shadow_only.
 *
 * Proves, at the SERVICE level (not command-level):
 *   1. The shadow PROOF gate still selects DISTINCT topologies for 2 task types
 *      and measures convergence in the real plan_trace.
 *   2. The SAME shared selector now drives the LIVE conductor dispatch when the
 *      flag is ON: 2 task types route through 2 distinct auto-composed topologies
 *      with measured convergence, and the envelope records WHICH topology drove
 *      each dispatch (topology_routing provenance).
 *   3. With the flag OFF (default) the conductor does NOT auto-compose — the
 *      legacy single-dispatch path is untouched (topology_routing null,
 *      effective_parallelism back to a single dispatch). Fail-open default.
 *   4. The selector is the single source of truth: composer and conductor agree.
 *
 * Sovereignty: every run here is SHADOW (the production-resolver flag stays OFF),
 * so NO provider token is spent. The frozen contract is the WIRING + the
 * measured convergence, not a live spend.
 */
final class AtlasSwarmTopologyLiveRoutingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Production resolver OFF: every run resolves to SHADOW (deterministic
        // planned winner, zero spend). The forced provider bootstraps one arm per
        // node so each node dispatches and the planned winner = success.
        config([
            'atlas.patamar4.swarm_production_resolver_enabled' => false,
            'atlas.patamar4.swarm_topology_auto_composer.enabled' => true,
            'atlas.patamar4.swarm_topology_auto_composer.min_task_types' => 2,
            'atlas.patamar4.swarm_topology_auto_composer.min_distinct_topologies' => 2,
            'atlas.patamar4.swarm_topology_auto_composer.min_convergence_rate' => 1.0,
            'atlas.patamar4.swarm_topology_auto_composer.forced_provider' => 'codex',
            'atlas.patamar4.swarm_topology_auto_composer.forced_model' => 'gpt-5.5',
        ]);
    }

    public function test_shadow_proof_gate_selects_distinct_topologies_and_measures_convergence(): void
    {
        $payload = app(AtlasSwarmTopologyAutoComposerService::class)->evaluate([
            'fixture' => 'two-types',
        ]);

        $this->assertSame('swarm_topologies_converged', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame(2, data_get($payload, 'summary.task_type_count'));
        $this->assertSame(2, data_get($payload, 'summary.topology_count'));
        $this->assertSame(2, data_get($payload, 'summary.converged_count'));

        $byTask = collect($payload['measurements'])->keyBy('task_category');
        $this->assertSame('debate', data_get($byTask->get('reasoning'), 'topology'));
        $this->assertSame('tournament', data_get($byTask->get('code_generation'), 'topology'));
        // Convergence is MEASURED from the real plan_trace, not asserted.
        $this->assertSame(1.0, data_get($byTask->get('reasoning'), 'convergence_rate'));
        $this->assertSame(1.0, data_get($byTask->get('code_generation'), 'convergence_rate'));
    }

    public function test_live_routing_flag_drives_distinct_topologies_through_the_conductor(): void
    {
        config(['atlas.patamar4.swarm_topology_live_routing_enabled' => true]);

        $conductor = app(AtlasEngineeringRunConductorService::class);

        // Two DIFFERENT task types -> the conductor must auto-compose two DIFFERENT
        // topologies and drive each through the governed runPlan().
        $reasoning = $conductor->run($this->work('reasoning'), $this->runOptions());
        $codeGen = $conductor->run($this->work('code_generation'), $this->runOptions());

        // Both ran the auto-composed plan-DAG (executed status, not single dispatch).
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $reasoning['status']);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $codeGen['status']);

        // The envelope records WHICH topology drove each dispatch — distinct.
        $this->assertSame('debate', data_get($reasoning, 'topology_routing.topology'));
        $this->assertSame('tournament', data_get($codeGen, 'topology_routing.topology'));
        $this->assertNotSame(
            data_get($reasoning, 'topology_routing.topology'),
            data_get($codeGen, 'topology_routing.topology'),
        );
        $this->assertTrue((bool) data_get($reasoning, 'topology_routing.live_routing_enabled'));
        $this->assertSame('auto_composed_by_task_type', data_get($codeGen, 'topology_routing.source'));

        // Measured convergence in the REAL plan_trace: every composed node
        // dispatched and the planned winner succeeded (no node_no_dispatch).
        $this->assertMeasuredConvergence($reasoning, 3); // debate: 3 nodes
        $this->assertMeasuredConvergence($codeGen, 3);   // tournament: 3 nodes

        // SHADOW guard held: zero provider spend (resolver flag is OFF).
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $reasoning['mode']);
        $this->assertSame(AtlasEngineeringRunConductorService::MODE_SHADOW, $codeGen['mode']);
    }

    public function test_flag_off_leaves_the_single_dispatch_path_untouched(): void
    {
        config(['atlas.patamar4.swarm_topology_live_routing_enabled' => false]);

        $envelope = app(AtlasEngineeringRunConductorService::class)
            ->run($this->work('code_generation'), $this->runOptions());

        // No auto-composition: legacy single dispatch (no plan_trace, no topology
        // provenance) — byte-for-byte the pre-L6-10 behaviour.
        $this->assertNull($envelope['topology_routing'] ?? null);
        $this->assertArrayNotHasKey('plan_trace', $envelope);
        $this->assertSame(AtlasEngineeringRunConductorService::STATUS_EXECUTED, $envelope['status']);
        // A single dispatch, not a 3-node topology fan-out.
        $this->assertSame(1, (int) $envelope['effective_parallelism']);
    }

    public function test_explicit_opt_out_wins_even_when_flag_is_on(): void
    {
        config(['atlas.patamar4.swarm_topology_live_routing_enabled' => true]);

        $envelope = app(AtlasEngineeringRunConductorService::class)->run(
            $this->work('code_generation'),
            $this->runOptions(['topology_auto_compose' => false]),
        );

        $this->assertNull($envelope['topology_routing'] ?? null);
        $this->assertSame(1, (int) $envelope['effective_parallelism']);
    }

    public function test_selector_is_the_single_source_of_truth(): void
    {
        $selector = new AtlasSwarmTopologySelector();

        // The composer and the conductor MUST agree on topology per task type —
        // they consume the same selector, so this is structural, not coincidental.
        $this->assertSame('debate', $selector->selectTopology('reasoning'));
        $this->assertSame('tournament', $selector->selectTopology('code_generation'));
        $this->assertSame('panel', $selector->selectTopology('audit'));
        $this->assertSame('pipeline', $selector->selectTopology('retrieval'));

        $composed = $selector->composeForTaskCategory('code_generation');
        $this->assertSame('tournament', $composed['topology']);
        $this->assertCount(3, $composed['plan']['nodes']);
    }

    /**
     * @return array<string,mixed>
     */
    private function work(string $taskCategory): array
    {
        return [
            'task_category' => $taskCategory,
            'role' => 'engineer',
            'framework' => null,
            'parallelism' => 1,
            'requested_autonomy' => 'suggest',
            'privacy_class' => 'normal',
            'scope' => ['privacy_class' => 'normal', 'surface' => 'l6-10_live_routing_test'],
            'input' => 'L6-10 service test for '.$taskCategory,
            // forced_provider bootstraps a real dispatch arm before ADML has
            // routing evidence — exactly the shadow gate's mechanism.
            'forced_provider' => 'codex',
            'forced_model' => 'gpt-5.5',
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function runOptions(array $extra = []): array
    {
        return array_merge([
            'mode' => AtlasEngineeringRunConductorService::MODE_SHADOW,
            'operator_approved' => false,
            'verify' => false,
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function assertMeasuredConvergence(array $envelope, int $expectedNodes): void
    {
        $nodes = (array) data_get($envelope, 'plan_trace.nodes', []);
        $this->assertCount($expectedNodes, $nodes, 'plan_trace node count mismatch');

        $dispatched = 0;
        foreach ($nodes as $node) {
            $this->assertSame(
                AtlasEngineeringRunConductorService::STATUS_EXECUTED,
                $node['status'] ?? null,
                'every composed node must dispatch (no no_dispatch)',
            );
            if (($node['winner_result'] ?? null) === 'success') {
                $dispatched++;
            }
        }
        $this->assertSame($expectedNodes, $dispatched, 'every node converged on a successful planned winner');
    }
}
