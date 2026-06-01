<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeImplementationRoadmapService;
use Tests\TestCase;

final class AtlasRuntimeImplementationRoadmapTest extends TestCase
{
    private AtlasRuntimeImplementationRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasRuntimeImplementationRoadmapService();
    }

    public function testPhaseLadderIsTheNineDocumentedPhasesInOrder(): void
    {
        $keys = array_column(AtlasRuntimeImplementationRoadmapService::PHASES, 'key');

        $this->assertSame([
            'documentation_and_registry',
            'read_only_gap_report',
            'meta_sdd_artifact_generator',
            'receipt_scoped_task_planner',
            'traceability_guardrail',
            'promotion_gate',
            'low_risk_agent_execution',
            'restricted_runtime_patches',
            'strategic_self_construction',
        ], $keys);

        // The doc's human phase labels are preserved, including 4.5 and 4.8.
        $labels = array_column(AtlasRuntimeImplementationRoadmapService::PHASES, 'label');
        $this->assertSame(['1', '2', '3', '4', '4.5', '4.8', '5', '6', '7'], $labels);
    }

    public function testPhaseGateBlocksNextPhaseWhenAnyOfTheFiveArtifactsIsMissing(): void
    {
        // Doc "Phase Gate": no phase may start until the previous phase has tests,
        // docs, evidence, architecture validation and explicit residual risk.
        // Missing evidence + residual risk -> two specific blockers, no start.
        $blocked = $this->service->evaluatePhaseStart('read_only_gap_report', [
            'tests' => true,
            'docs' => true,
            'evidence' => false,
            'architecture_validation' => true,
            'explicit_residual_risk' => false,
        ]);

        $this->assertFalse($blocked['may_start_next']);
        $this->assertSame('meta_sdd_artifact_generator', $blocked['next_phase']);
        $this->assertContains('previous_phase_missing_evidence', $blocked['blockers']);
        $this->assertContains('previous_phase_missing_explicit_residual_risk', $blocked['blockers']);
        $this->assertNotContains('previous_phase_missing_tests', $blocked['blockers']);

        // All five artifacts present -> the next phase may start, no blockers.
        $ok = $this->service->evaluatePhaseStart('read_only_gap_report', [
            'tests' => true,
            'docs' => true,
            'evidence' => true,
            'architecture_validation' => true,
            'explicit_residual_risk' => true,
        ]);
        $this->assertTrue($ok['may_start_next']);
        $this->assertSame([], $ok['blockers']);
    }

    public function testSkippingAPhaseIsForbidden(): void
    {
        // Phase 1 -> Phase 3 skips the Read-Only Gap Report (Phase 2).
        $verdict = $this->service->evaluateAdvancement('documentation_and_registry', 'meta_sdd_artifact_generator');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('rejected', $verdict['verdict']);
        $this->assertSame('forbidden_phase_skip', $verdict['reason']);
    }

    public function testAdvancementRequiresPriorPhasesCompleteAndPhaseGateSatisfied(): void
    {
        $full = [
            'tests' => true,
            'docs' => true,
            'evidence' => true,
            'architecture_validation' => true,
            'explicit_residual_risk' => true,
        ];

        // Prior phase not complete -> blocked even with a full source gate.
        $blockedPrior = $this->service->evaluateAdvancement(
            'read_only_gap_report',
            'meta_sdd_artifact_generator',
            ['read_only_gap_report'], // Phase 1 missing from completed set.
            $full,
        );
        $this->assertFalse($blockedPrior['allowed']);
        $this->assertSame('blocked', $blockedPrior['verdict']);
        $this->assertSame('prior_phase_incomplete:documentation_and_registry', $blockedPrior['reason']);

        // Priors complete but source Phase Gate incomplete -> blocked on the gate.
        $blockedGate = $this->service->evaluateAdvancement(
            'read_only_gap_report',
            'meta_sdd_artifact_generator',
            ['documentation_and_registry', 'read_only_gap_report'],
            ['tests' => true, 'docs' => true, 'evidence' => false, 'architecture_validation' => true, 'explicit_residual_risk' => true],
        );
        $this->assertFalse($blockedGate['allowed']);
        $this->assertSame('phase_gate_not_satisfied', $blockedGate['reason']);
        $this->assertContains('previous_phase_missing_evidence', $blockedGate['gate_blockers']);

        // Priors complete AND full Phase Gate -> the single-step advance is allowed.
        $allowed = $this->service->evaluateAdvancement(
            'read_only_gap_report',
            'meta_sdd_artifact_generator',
            ['documentation_and_registry', 'read_only_gap_report'],
            $full,
        );
        $this->assertTrue($allowed['allowed']);
        $this->assertSame('allowed', $allowed['verdict']);
    }

    public function testRuntimeBeginsReadOnlyBeforeAutonomousPatching(): void
    {
        // Every phase up to and including the Promotion Gate (4.8) is read-only/advisory.
        $promotionGate = $this->service->classifyExecution('promotion_gate');
        $this->assertTrue($promotionGate['read_only_advisory']);
        $this->assertFalse($promotionGate['executes']);
        $this->assertFalse($promotionGate['may_patch_code']);

        // Phase 5 (Low-Risk Agent Execution) executes but does NOT patch code.
        $lowRisk = $this->service->classifyExecution('low_risk_agent_execution');
        $this->assertTrue($lowRisk['executes']);
        $this->assertFalse($lowRisk['may_patch_code']);
        $this->assertFalse($lowRisk['read_only_advisory']);

        // Phase 6 (Restricted Runtime Patches) is the first phase allowed to patch code.
        $patches = $this->service->classifyExecution('restricted_runtime_patches');
        $this->assertTrue($patches['executes']);
        $this->assertTrue($patches['may_patch_code']);

        // The maturity read model asserts the read-only-first posture.
        $maturity = $this->service->maturity();
        $this->assertTrue($maturity['runtime_begins_read_only_advisory']);
        $this->assertFalse($maturity['autonomous_patching_enabled']);
        $this->assertSame('low_risk_agent_execution', $maturity['first_execution_phase']);
        $this->assertSame('restricted_runtime_patches', $maturity['first_patching_phase']);
    }

    public function testReadinessClaimRefusedWithoutEvidenceAndGreenGates(): void
    {
        // frontmatter forbidden_changes: no readiness/maturity claim without
        // verifiable evidence AND green gates.
        $refused = $this->service->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']);
        $this->assertFalse($refused['claim_allowed']);
        $this->assertContains('no_readiness_claim_without_verifiable_evidence', $refused['blockers']);
        $this->assertContains('no_readiness_claim_without_green_gates', $refused['blockers']);

        // Evidence present but gate not green -> still refused on the gate.
        $gateRed = $this->service->claimGuard(['evidence_ref' => 'docs-health.json', 'gate_status' => 'failed']);
        $this->assertFalse($gateRed['claim_allowed']);
        $this->assertContains('no_readiness_claim_without_green_gates', $gateRed['blockers']);
        $this->assertNotContains('no_readiness_claim_without_verifiable_evidence', $gateRed['blockers']);

        // Evidence cited AND gate green -> the claim is allowed.
        $allowed = $this->service->claimGuard(['evidence_ref' => 'docs-health.json', 'gate_status' => 'ok']);
        $this->assertTrue($allowed['claim_allowed']);
        $this->assertSame([], $allowed['blockers']);
    }
}
