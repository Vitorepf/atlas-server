<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionOrganContractCoverageGate;
use Tests\TestCase;

final class AtlasSelfConstructionOrganContractCoverageGateTest extends TestCase
{
    private function fullCoverageRegistry(): array
    {
        $registry = [];
        foreach (AtlasSelfConstructionOrganContractCoverageGate::CANONICAL_ORGANS as $organ) {
            $registry[$organ] = [
                'task_packet' => 'pkt-'.$organ,
                'implementation_surface' => 'app/Services/Ai/SelfConstruction/'.$organ.'.php',
                'test_evidence_requirement' => 'phpunit:'.$organ,
                'replenishment_contract' => 'replenish:'.$organ,
                'runtime_owner' => 'atlas_native',
                'runtime_integration_owner' => 'atlas_native',
            ];
        }

        return $registry;
    }

    public function test_full_coverage_is_fully_covered(): void
    {
        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($this->fullCoverageRegistry());

        $this->assertTrue($verdict['fully_covered']);
        foreach (['missing_organ', 'missing_gate', 'missing_receipt', 'autonomy_regression'] as $key) {
            $this->assertSame([], $verdict[$key]);
        }
    }

    public function test_one_missing_organ_is_listed(): void
    {
        $registry = $this->fullCoverageRegistry();
        unset($registry['native_worker']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertContains('native_worker', $verdict['missing_organ']);
    }

    public function test_non_atlas_runtime_owner_is_autonomy_regression(): void
    {
        $registry = $this->fullCoverageRegistry();
        $registry['merge_governor']['runtime_owner'] = 'operator';

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertContains('merge_governor:runtime_owner_not_atlas_native:operator', $verdict['autonomy_regression']);
    }

    public function test_missing_test_evidence_requirement_goes_to_missing_gate(): void
    {
        $registry = $this->fullCoverageRegistry();
        unset($registry['cortex']['test_evidence_requirement']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);
        $this->assertContains('cortex:test_evidence_requirement', $verdict['missing_gate']);
    }

    public function test_missing_task_packet_goes_to_missing_receipt(): void
    {
        $registry = $this->fullCoverageRegistry();
        unset($registry['cortex']['task_packet']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);
        $this->assertContains('cortex:task_packet', $verdict['missing_receipt']);
    }

    public function test_verdict_carries_no_scalar_score(): void
    {
        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($this->fullCoverageRegistry());
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
            $this->assertStringNotContainsString('rank', strtolower((string) $key));
        }
    }

    public function test_duplicate_implementation_surface_is_flagged(): void
    {
        $registry = $this->fullCoverageRegistry();
        // Two organs share the same implementation_surface.
        $sharedSurface = 'app/Services/Ai/SelfConstruction/SharedModule.php';
        $registry['cortex']['implementation_surface'] = $sharedSurface;
        $registry['strategy']['implementation_surface'] = $sharedSurface;

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertCount(1, $verdict['duplicate_surfaces']);
        $this->assertStringContainsString($sharedSurface, $verdict['duplicate_surfaces'][0]);
        $this->assertStringContainsString('cortex', $verdict['duplicate_surfaces'][0]);
        $this->assertStringContainsString('strategy', $verdict['duplicate_surfaces'][0]);
    }

    public function test_missing_replenishment_contract_goes_to_missing_receipt(): void
    {
        $registry = $this->fullCoverageRegistry();
        unset($registry['maestro']['replenishment_contract']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertContains('maestro:replenishment_contract', $verdict['missing_receipt']);
    }

    public function test_missing_runtime_integration_owner_is_autonomy_regression(): void
    {
        $registry = $this->fullCoverageRegistry();
        unset($registry['knowledge_sync']['runtime_integration_owner']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertContains('knowledge_sync:runtime_integration_owner_missing', $verdict['autonomy_regression']);
    }

    public function test_human_runtime_integration_owner_is_provider_regression(): void
    {
        $registry = $this->fullCoverageRegistry();
        $registry['verification_court']['runtime_integration_owner'] = 'human';

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluate($registry);

        $this->assertFalse($verdict['fully_covered']);
        $this->assertContains('verification_court:runtime_integration_owner_regression:human', $verdict['autonomy_regression']);
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionOrganContractCoverageGate;
        $reg = $this->fullCoverageRegistry();
        $this->assertSame(json_encode($svc->evaluate($reg)), json_encode($svc->evaluate($reg)));
    }

    // ── evaluateReadiness(): critical_uncovered_organs / weak_tests / stale_contracts / missing_runtime_proof ──

    private function readyRegistry(): array
    {
        $registry = $this->fullCoverageRegistry();
        foreach (AtlasSelfConstructionOrganContractCoverageGate::CANONICAL_ORGANS as $organ) {
            $registry[$organ]['test_evidence_requirement'] = 'php artisan test '.$organ;
            $registry[$organ]['contract_verified_days_ago'] = 1;
            $registry[$organ]['runtime_proof_present'] = true;
        }

        return $registry;
    }

    // ── AC: full coverage ─────────────────────────────────────────────────────────

    public function test_full_readiness_coverage_reports_ready_with_no_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluateReadiness($this->readyRegistry());

        $this->assertSame(AtlasSelfConstructionOrganContractCoverageGate::READINESS_READY, $verdict['readiness_status']);
        foreach (['critical_uncovered_organs', 'weak_tests', 'stale_contracts', 'missing_runtime_proof', 'blockers'] as $key) {
            $this->assertSame([], $verdict[$key], "expected {$key} empty");
        }
    }

    // ── AC: missing critical organ ────────────────────────────────────────────────

    public function test_missing_critical_organ_blocks_readiness(): void
    {
        $registry = $this->readyRegistry();
        unset($registry['native_worker']);

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluateReadiness($registry);

        $this->assertContains('native_worker', $verdict['critical_uncovered_organs']);
        $this->assertContains('native_worker', $verdict['blockers']);
        $this->assertSame(AtlasSelfConstructionOrganContractCoverageGate::READINESS_BLOCKED, $verdict['readiness_status']);
    }

    // ── AC: stale contract ────────────────────────────────────────────────────────

    public function test_stale_contract_blocks_readiness(): void
    {
        $registry = $this->readyRegistry();
        $registry['cortex']['contract_verified_days_ago'] = 200;

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluateReadiness($registry);

        $this->assertContains('cortex:stale_contract:200_days', $verdict['stale_contracts']);
        $this->assertContains('cortex:stale_contract:200_days', $verdict['blockers']);
        $this->assertSame(AtlasSelfConstructionOrganContractCoverageGate::READINESS_BLOCKED, $verdict['readiness_status']);
    }

    // ── AC: weak test-only coverage ──────────────────────────────────────────────

    public function test_weak_test_evidence_requirement_is_flagged_but_not_a_hard_blocker(): void
    {
        $registry = $this->readyRegistry();
        $registry['strategy']['test_evidence_requirement'] = 'looks fine to me';

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluateReadiness($registry);

        $this->assertContains('strategy:weak_test_evidence_requirement', $verdict['weak_tests']);
        $this->assertNotContains('strategy:weak_test_evidence_requirement', $verdict['blockers']);
        $this->assertSame(AtlasSelfConstructionOrganContractCoverageGate::READINESS_PARTIAL, $verdict['readiness_status']);
    }

    // ── AC: missing runtime proof ─────────────────────────────────────────────────

    public function test_missing_runtime_proof_blocks_readiness_even_with_declared_owner(): void
    {
        $registry = $this->readyRegistry();
        $registry['maestro']['runtime_proof_present'] = false;

        $verdict = (new AtlasSelfConstructionOrganContractCoverageGate)->evaluateReadiness($registry);

        $this->assertContains('maestro:missing_runtime_proof', $verdict['missing_runtime_proof']);
        $this->assertContains('maestro:missing_runtime_proof', $verdict['blockers']);
        $this->assertSame(AtlasSelfConstructionOrganContractCoverageGate::READINESS_BLOCKED, $verdict['readiness_status']);
    }

    public function test_readiness_evaluation_is_deterministic(): void
    {
        $svc = new AtlasSelfConstructionOrganContractCoverageGate;
        $reg = $this->readyRegistry();
        $this->assertSame($svc->evaluateReadiness($reg), $svc->evaluateReadiness($reg));
    }
}
