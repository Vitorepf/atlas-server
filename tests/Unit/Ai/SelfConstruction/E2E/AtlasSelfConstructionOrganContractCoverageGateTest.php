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
                'runtime_owner' => 'atlas_native',
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

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionOrganContractCoverageGate;
        $reg = $this->fullCoverageRegistry();
        $this->assertSame(json_encode($svc->evaluate($reg)), json_encode($svc->evaluate($reg)));
    }
}
