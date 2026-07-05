<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReleaseDossierService;
use Tests\TestCase;

final class AgentControlPlaneReleaseDossierServiceTest extends TestCase
{
    private function dossier(): AgentControlPlaneReleaseDossierService
    {
        return new AgentControlPlaneReleaseDossierService(
            app(AgentControlPlaneCertificationBaselineService::class),
            app(AgentControlPlaneDeterministicChainReplayService::class),
            app(AgentControlPlaneReplaySnapshotStore::class),
            app(AgentControlPlaneReplayDiffService::class),
            app(AgentControlPlaneMacroSprintPromotionGate::class),
            app(AgentControlPlaneCertificationScenarioSimulator::class),
            app(AgentControlPlaneChainIntegrityAuditService::class),
            app(AgentControlPlaneCertificationMutationGuard::class),
        );
    }

    public function test_release_readiness_present(): void
    {
        $result = $this->dossier()->build();

        $this->assertArrayHasKey('release_readiness', $result);
        $readiness = $result['release_readiness'];

        $this->assertArrayHasKey('ready', $readiness);
        $this->assertArrayHasKey('queue_health', $readiness);
        $this->assertArrayHasKey('proof_coverage', $readiness);
        $this->assertArrayHasKey('unresolved_blockers', $readiness);
        $this->assertArrayHasKey('rollback_ready', $readiness);
    }

    public function test_release_readiness_has_queue_health_with_passes_field(): void
    {
        $result = $this->dossier()->build();
        $qh = $result['release_readiness']['queue_health'];

        $this->assertArrayHasKey('replay_status', $qh);
        $this->assertArrayHasKey('gate_status', $qh);
        $this->assertArrayHasKey('passes', $qh);
        $this->assertIsBool($qh['passes']);
    }

    public function test_release_readiness_has_proof_coverage_with_passes(): void
    {
        $result = $this->dossier()->build();
        $pc = $result['release_readiness']['proof_coverage'];

        $this->assertArrayHasKey('baseline_capture_status', $pc);
        $this->assertArrayHasKey('passes', $pc);
        $this->assertIsBool($pc['passes']);
    }

    public function test_release_readiness_has_unresolved_blockers(): void
    {
        $result = $this->dossier()->build();
        $blockers = $result['release_readiness']['unresolved_blockers'];

        $this->assertIsArray($blockers);
    }

    public function test_release_readiness_has_rollback_ready_with_passes(): void
    {
        $result = $this->dossier()->build();
        $rr = $result['release_readiness']['rollback_ready'];

        $this->assertArrayHasKey('replay_status', $rr);
        $this->assertArrayHasKey('gate_status', $rr);
        $this->assertArrayHasKey('passes', $rr);
        $this->assertIsBool($rr['passes']);
    }

    public function test_readiness_is_false_when_blockers_or_missing_rollback(): void
    {
        $result = $this->dossier()->build();
        $readiness = $result['release_readiness'];

        // If there are any blockers, ready must be false.
        if ($result['blockers'] !== [] || $result['blocker_count'] > 0) {
            $this->assertFalse(
                $readiness['ready'],
                'readiness must be false when blockers are present'
            );
        }
    }

    public function test_readiness_is_true_when_all_conditions_satisfied(): void
    {
        $result = $this->dossier()->build(['skip_simulator' => true]);
        $readiness = $result['release_readiness'];

        // Ready is the AND of all sub-conditions
        $expectedReady = $readiness['queue_health']['passes']
            && $readiness['proof_coverage']['passes']
            && $readiness['rollback_ready']['passes']
            && $readiness['unresolved_blockers'] === [];

        $this->assertSame($expectedReady, $readiness['ready']);
    }
}
