<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraAutoMergeService;
use App\Services\Ai\Obra\AtlasObraExecutor;
use Tests\TestCase;

/**
 * Proves the anti-farm floor gate (flag-gated) in AtlasLoopObraAutoMergeService — sits BEFORE
 * NetDirection/BroaderGate, fail-closed on missing acceptance_contract, red on missing bite-proof,
 * red on wired-without-production_caller, and transparent (lets the existing chain continue) on a
 * fully-evidenced contract.
 *
 * The negative cases never reach the branch-exists check — they return red right after certification,
 * so no real git repo is needed. The happy-path is proven by the fact that the result status is NOT
 * 'anti_farm_floor_red' (the floor permitted the existing chain to continue and the next step took over).
 */
final class AtlasLoopObraAutoMergeAntiFarmFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'atlas.loop.obra_auto_merge_enabled' => true,
            'atlas.loop.obra_auto_merge_require_trust' => false,
            'atlas.loop.obra_auto_merge_anti_farm_floor_enabled' => true,
        ]);
    }

    private function service(): AtlasLoopObraAutoMergeService
    {
        return $this->app->make(AtlasLoopObraAutoMergeService::class);
    }

    /**
     * @param  array<string,mixed>|null  $acceptanceContract
     * @return array<string,mixed>
     */
    private function certifiedObra(string $obraId, ?array $acceptanceContract): array
    {
        $branch = 'atlas/obra/'.$obraId;
        $obra = [
            'schema' => AtlasObraExecutor::SCHEMA,
            'plan_id' => $obraId,
            'status' => AtlasObraExecutor::STATUS_DONE,
            'branch' => $branch,
            'node_count' => 1,
            'delivered_nodes' => 1,
            'failed_node' => null,
            'certified' => true,
            'nodes' => [['id' => $obraId.':n0', 'seq' => 0, 'status' => AtlasObraExecutor::NODE_DONE, 'files_changed' => ['feature.php'], 'commit' => 'abc']],
            'integrated_test_result' => ['supplied' => true, 'ran' => true, 'passed' => true, 'exit_code' => 0],
            'main_untouched' => true,
            'never_merged' => true,
            'never_pushed' => true,
        ];
        if ($acceptanceContract !== null) {
            $obra['acceptance_contract'] = $acceptanceContract;
        }

        return $obra;
    }

    private function fakeRepo(): string
    {
        $d = sys_get_temp_dir().'/atlas_obra_floor_fakerepo_'.bin2hex(random_bytes(4));
        mkdir($d.'/.git', 0o755, true); // satisfies the `is_dir($repoRoot.'/.git')` check

        return $d;
    }

    public function test_happy_path_full_evidence_with_bite_proof_and_production_caller_passes_the_floor(): void
    {
        $contract = [
            'diff_earned' => true,        // bite-proof: revert→red
            'wired_proof' => true,
            'production_caller' => true,  // wired evidence requires production caller
        ];
        $obra = $this->certifiedObra('obra-happy', $contract);

        $result = $this->service()->autoMerge($obra, $this->fakeRepo());

        // The floor PASSED (not red); the chain continued to the next gate (branch-exists check fails
        // on the fake repo, returning 'obra_branch_missing_in_repo' — proves we made it past the floor).
        $this->assertNotSame('anti_farm_floor_red', $result['status'], 'fully-evidenced contract must pass the floor');
        $this->assertSame('obra_branch_missing_in_repo:atlas/obra/obra-happy', $result['reason'] ?? null, 'next gate took over');
    }

    public function test_no_bite_proof_returns_anti_farm_floor_red_no_merge_side_effect(): void
    {
        // No diff_earned / method_kills / earned_red / count_drop — bites=false ⇒ floor red.
        $contract = ['wired_proof' => false, 'production_caller' => true];
        $result = $this->service()->autoMerge($this->certifiedObra('obra-no-bite', $contract), $this->fakeRepo());

        $this->assertSame('anti_farm_floor_red', $result['status']);
        $this->assertFalse($result['merged']);
        $this->assertStringContainsString('not_load_bearing', (string) ($result['reason'] ?? ''));
    }

    public function test_wired_without_production_caller_is_blocked_by_the_floor(): void
    {
        $contract = [
            'diff_earned' => true,        // bite-proof present
            'wired_proof' => true,
            // production_caller intentionally omitted
        ];
        $result = $this->service()->autoMerge($this->certifiedObra('obra-wired-only', $contract), $this->fakeRepo());

        $this->assertSame('anti_farm_floor_red', $result['status']);
        $this->assertFalse($result['merged']);
        $this->assertStringContainsString('wired_into_non_production_path', (string) ($result['reason'] ?? ''));
    }

    public function test_missing_acceptance_contract_fails_closed_with_canonical_reason(): void
    {
        $obra = $this->certifiedObra('obra-no-contract', null);
        $result = $this->service()->autoMerge($obra, $this->fakeRepo());

        $this->assertSame('anti_farm_floor_red', $result['status']);
        $this->assertSame('obra_acceptance_contract_unavailable', $result['reason']);
        $this->assertFalse($result['merged']);
    }

    public function test_floor_runs_before_net_direction_no_call_into_net_direction_when_floor_is_red(): void
    {
        // Force a NetDirectionGuard stub that would TRIP if called — we assert it was never reached.
        $netCalled = false;
        $this->app->bind(\App\Services\Ai\AutonomousEvolution\AtlasLoopNetDirectionGuard::class, function () use (&$netCalled) {
            return new class($netCalled)
            {
                public function __construct(private bool &$called) {}

                public function verdict(): array
                {
                    $this->called = true;

                    return ['throttled' => false, 'reason' => null];
                }
            };
        });
        $contract = ['wired_proof' => false]; // no bite proofs
        $this->service()->autoMerge($this->certifiedObra('obra-order', $contract), $this->fakeRepo());

        $this->assertFalse($netCalled, 'floor must short-circuit BEFORE NetDirection is asked');
    }

    public function test_floor_disabled_by_default_does_not_change_legacy_behavior(): void
    {
        config(['atlas.loop.obra_auto_merge_anti_farm_floor_enabled' => false]);
        $obra = $this->certifiedObra('obra-legacy', null); // no contract — but flag is OFF
        $result = $this->service()->autoMerge($obra, $this->fakeRepo());

        $this->assertNotSame('anti_farm_floor_red', $result['status'], 'floor off ⇒ flow unchanged even without contract');
    }
}
