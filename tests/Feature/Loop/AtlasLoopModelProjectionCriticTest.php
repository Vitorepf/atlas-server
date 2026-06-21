<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopGroundedProjectionRoles;
use App\Services\Ai\AutonomousEvolution\AtlasLoopModelProjectionCritic;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §3 · ARCHITECT PHASE — the CROSS-MODEL critique seam is SAFE BY CONSTRUCTION: the frontier model can only
 * ADD obligations the frozen engine grounds; a fabricated mutation operator is dropped, never a weakening.
 * The deepened obligation rides into the converged contract through the deterministic floor; no provider ⇒
 * the floor stands alone (fail-closed).
 */
final class AtlasLoopModelProjectionCriticTest extends TestCase
{
    private string $realMutop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realMutop = (string) array_key_first(AtlasLoopMutationOperators::map());
    }

    public function test_grounds_a_real_obligation_and_drops_a_fabricated_one(): void
    {
        $response = implode("\n", [
            '<<<OBLIGATION>>>', 'kind=mutation_killed', 'assertion=mutop:'.$this->realMutop,
            '<<<OBLIGATION>>>', 'kind=mutation_killed', 'assertion=mutop:totally_fake_operator_xyz',
            '<<<OBLIGATION>>>', 'kind=not_a_real_kind', 'assertion=mutop:'.$this->realMutop,
            '<<<END>>>',
        ]);
        $grounded = (new AtlasLoopModelProjectionCritic)->parseAndGround($response, 'app/X/Foo.php');

        $this->assertCount(1, $grounded, 'only the real, registry-grounded obligation survives');
        $this->assertSame('mutation_killed', $grounded[0]['kind']);
        $this->assertSame('app/x/foo.php', strtolower($grounded[0]['target_symbol']));
    }

    public function test_no_provider_yields_no_extra_obligations_fail_closed(): void
    {
        config(['atlas.loop.default_provider' => '']);
        $this->assertSame([], (new AtlasLoopModelProjectionCritic)->additionalObligations('app/X/Foo.php', 'wired'));
    }

    public function test_a_model_deepened_obligation_rides_into_the_converged_contract(): void
    {
        // The model proposes a grounded perf_bound obligation via an injected completion double; it joins the
        // deterministic floor as an extra design seed and appears in the converged contract.
        $response = "<<<OBLIGATION>>>\nkind=perf_bound\nassertion=mutop:".$this->realMutop."\n<<<END>>>";
        $extra = (new AtlasLoopModelProjectionCritic(fn (string $p, string $pr): string => $response))
            ->additionalObligations('app/Services/Ai/AutonomousEvolution/Hub.php', 'non_trivial');
        $this->assertCount(1, $extra);

        $target = 'app/Services/Ai/AutonomousEvolution/Hub.php';
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target)))->forTarget($target, $extra);
        $res = (new AtlasLoopProjectionEngine)->project('non_trivial', $roles['designer'], $roles['critic'], 8);

        $this->assertSame('converged', $res['status'], (string) ($res['reason'] ?? ''));
        $this->assertContains('perf_bound', array_column($res['obligations'], 'kind'), 'the model-deepened obligation is in the contract');
    }

    private function model(string $target): AtlasLoopScopeComprehensionModel
    {
        $caller = 'app/Services/Ai/AutonomousEvolution/CallerA.php';

        return new AtlasLoopScopeComprehensionModel(
            inventory: array_map(static fn (string $p): array => ['rel_path' => $p, 'fqcn' => 'App\\'.basename($p, '.php'), 'public_methods' => ['run'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null], [$target, $caller]),
            edges: [$target => [$caller]],
            orphans: [], cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [],
            snapshotId: hash('sha256', $target),
        );
    }
}
