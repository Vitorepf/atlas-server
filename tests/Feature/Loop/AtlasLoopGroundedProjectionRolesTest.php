<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopGroundedProjectionRoles;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §3 · ARCHITECT PHASE — the grounded design↔critique critic bites on GROUND TRUTH (the comprehension
 * model's real who-calls-who edges), run through the REAL frozen {@see AtlasLoopProjectionEngine}:
 *   (1) a target with real callers converges on a contract that PROVABLY contains a consumer_intact
 *       obligation for EVERY measured caller — a design that omits a caller cannot converge;
 *   (2) a zero-caller leaf still earns a material mutation_killed critique and converges (the critic
 *       provably ENGAGED — it is not a silent rubber-stamp);
 *   (3) a target whose caller fan-out exceeds the round budget PARKS (blast-radius too large) — a
 *       reachable, ground-truth-driven rejection.
 */
final class AtlasLoopGroundedProjectionRolesTest extends TestCase
{
    private AtlasLoopProjectionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AtlasLoopProjectionEngine;
    }

    public function test_critic_raises_a_consumer_intact_obligation_for_every_real_caller(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Hub.php';
        $callers = ['app/Services/Ai/AutonomousEvolution/CallerA.php', 'app/Services/Ai/AutonomousEvolution/CallerB.php'];
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target, $callers)))->forTarget($target);

        $res = $this->engine->project('wired', $roles['designer'], $roles['critic'], 8);

        $this->assertSame('converged', $res['status'], (string) ($res['reason'] ?? ''));
        $this->assertTrue($res['critic_engaged']);

        // The converged contract carries a consumer_intact obligation for EACH real caller — the bite.
        $coveredConsumers = $this->consumerTargets($res['obligations']);
        foreach ($callers as $caller) {
            $this->assertContains(
                strtolower($caller),
                $coveredConsumers,
                'the projected contract must protect the real caller '.$caller,
            );
        }
        $this->assertSame(2, $roles['consumer_count']);
    }

    public function test_a_design_that_omits_a_real_caller_cannot_converge_until_it_is_covered(): void
    {
        // Drive the engine ourselves with a designer that SEEDS but never accepts the critique, while the
        // grounded critic keeps raising the real callers. Because the seed never covers them and the budget
        // is one round short of the fan-out, the set never stabilizes ⇒ PARK (a silent omission cannot pass).
        $target = 'app/Services/Ai/AutonomousEvolution/Hub.php';
        $callers = ['app/Services/Ai/AutonomousEvolution/CallerA.php', 'app/Services/Ai/AutonomousEvolution/CallerB.php', 'app/Services/Ai/AutonomousEvolution/CallerC.php'];
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target, $callers)))->forTarget($target);

        // 3 callers need ≥5 rounds to stabilize; cap at 3 ⇒ the grounded critic is still raising callers ⇒ PARK.
        $res = $this->engine->project('wired', $roles['designer'], $roles['critic'], 3);

        $this->assertSame('parked', $res['status']);
        $this->assertSame('oscillation_no_content_fixpoint', $res['reason']);
    }

    public function test_a_zero_caller_leaf_still_earns_a_material_mutation_critique_and_converges(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Leaf.php';
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target, [])))->forTarget($target);

        $res = $this->engine->project('non_trivial', $roles['designer'], $roles['critic'], 8);

        $this->assertSame('converged', $res['status'], (string) ($res['reason'] ?? ''));
        $this->assertTrue($res['critic_engaged'], 'a zero-caller leaf still draws a material critique (not a silent pass)');
        $kinds = array_column($res['obligations'], 'kind');
        $this->assertContains('mutation_killed', $kinds, 'the anti-empty-test floor obligation was raised');
        $this->assertSame(0, $roles['consumer_count']);
    }

    public function test_high_fan_out_target_parks_blast_radius_too_large_to_auto_originate(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/MegaHub.php';
        $callers = [];
        for ($i = 0; $i < 12; $i++) {
            $callers[] = 'app/Services/Ai/AutonomousEvolution/Caller'.$i.'.php';
        }
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target, $callers)))->forTarget($target);

        // Default engine round budget (8) cannot raise+stabilize 12 callers ⇒ a real, reachable PARK.
        $res = $this->engine->project('wired', $roles['designer'], $roles['critic'], 8);

        $this->assertSame('parked', $res['status'], 'a 12-caller blast radius exceeds the projection budget ⇒ park for review');
        $this->assertSame(12, $roles['consumer_count']);
    }

    /**
     * @param  list<string>  $callers
     */
    private function model(string $target, array $callers): AtlasLoopScopeComprehensionModel
    {
        $paths = array_values(array_unique(array_merge([$target], $callers)));
        $inventory = array_map(static fn (string $p): array => [
            'rel_path' => $p,
            'fqcn' => 'App\\'.str_replace('/', '\\', substr($p, 0, -4)),
            'public_methods' => ['run'],
            'is_orphan' => false,
            'is_forbidden' => false,
            'clone_cluster_id' => null,
        ], $paths);

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [$target => $callers],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: hash('sha256', $target.implode('', $callers)),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $obligations
     * @return list<string>
     */
    private function consumerTargets(array $obligations): array
    {
        $out = [];
        foreach ($obligations as $o) {
            if (($o['kind'] ?? '') === 'consumer_intact') {
                $out[] = (string) ($o['target_symbol'] ?? '');
            }
        }

        return $out;
    }
}
