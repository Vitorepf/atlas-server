<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopGroundedProjectionRoles;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkTypeContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * §2 · WORK-TYPE CONTRACT — the architect phase designs each work type knowing the PROOF it owes: a dedup's
 * projected contract carries complexity_reduced, a bug-fix's carries red_to_green, a perf's carries
 * perf_bound. The mandatory obligation is grounded through the SAME frozen engine, so a design cannot
 * converge into a contract that hides its type's anti-Goodhart proof. An unknown kind owes nothing extra
 * (the floor is unchanged) — never an invented proof.
 */
final class AtlasLoopWorkTypeContractTest extends TestCase
{
    public function test_registry_declares_axis_and_mandatory_proof_per_type_and_nothing_for_unknown(): void
    {
        $c = new AtlasLoopWorkTypeContract;

        $this->assertSame('complexity_reduced', $c->mandatoryKind('dedup'));
        $this->assertSame('complexity_reduced', $c->mandatoryKind('clone_unification'));
        $this->assertSame('red_to_green', $c->mandatoryKind('bug_fix'));
        $this->assertSame('perf_bound', $c->mandatoryKind('perf'));
        $this->assertSame('consumer_intact', $c->mandatoryKind('orphan_wiring'));
        $this->assertSame('real_target', $c->bindingAxis('bug_fix'));
        $this->assertSame('non_trivial', $c->bindingAxis('perf'));

        // unknown kind owes nothing — never fabricate a proof a type does not own.
        $this->assertNull($c->mandatoryKind('totally_unknown_kind'));
        $this->assertNull($c->mandatoryObligation('totally_unknown_kind', 'app/x.php', 'tests/xTest.php'));

        // the mandatory obligation is grounded in the form the engine accepts for that kind.
        $bug = $c->mandatoryObligation('bug_fix', 'app/x.php', 'tests/Unit/XTest.php');
        $this->assertSame('red_to_green', $bug['kind']);
        $this->assertStringStartsWith('chartest:', $bug['assertion_ref']);
        $orphan = $c->mandatoryObligation('orphan_wiring', 'app/x.php', '');
        $this->assertStringStartsWith('consumer:', $orphan['assertion_ref']);
    }

    #[DataProvider('workTypes')]
    public function test_a_typed_projection_contract_provably_carries_its_mandatory_proof(string $kind, string $mandatoryKind): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Hub.php';
        $axis = (new AtlasLoopWorkTypeContract)->bindingAxis($kind) ?? 'non_trivial';
        $roles = (new AtlasLoopGroundedProjectionRoles($this->model($target)))->forTarget($target, [], $kind);

        $res = (new AtlasLoopProjectionEngine)->project($axis, $roles['designer'], $roles['critic'], 8);

        $this->assertSame('converged', $res['status'], (string) ($res['reason'] ?? ''));
        $this->assertContains($mandatoryKind, array_column($res['obligations'], 'kind'), "$kind must commit to $mandatoryKind");
    }

    /** @return list<array{0:string,1:string}> */
    public static function workTypes(): array
    {
        return [
            ['dedup', 'complexity_reduced'],
            ['bug_fix', 'red_to_green'],
            ['perf', 'perf_bound'],
            ['refactor_reduce_complexity', 'complexity_reduced'],
        ];
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
