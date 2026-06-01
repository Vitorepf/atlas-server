<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofMobileAppOnboardingService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo", "Exemplos", "Riscos" and headline
 * "Regras para IA" of the mobile app-onboarding manifest. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-mobile_app_onboarding.md
 */
class AtlasProgrammingFrontendProductProofMobileAppOnboardingTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendProductProofMobileAppOnboardingService
    {
        return new AtlasProgrammingFrontendProductProofMobileAppOnboardingService;
    }

    public function test_canonical_sample_passes_every_documented_gate(): void
    {
        $service = $this->service();
        $result = $service->evaluate($service->readySample());

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['ready']);
        $this->assertTrue($result['gates_passed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['claim_violations']);
        $this->assertSame('mobile_app_onboarding', $result['demo_id']);
        // Escopo de Implementacao: a manifest never authorizes an executable app.
        $this->assertFalse($result['executable_app_authorized']);
    }

    public function test_missing_mobile_viewport_blocks_proof(): void
    {
        // Contratos: viewport mobile is required.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['viewports'] = ['desktop'];

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['gates_passed']);
        $this->assertContains('missing_viewport:mobile', $result['blockers']);
        $this->assertFalse($result['viewport_checks']['mobile']);
    }

    public function test_missing_state_transition_is_a_static_mock_and_blocks(): void
    {
        // Riscos: "Confundir mock estatico com fluxo mobile" — state_transition is
        // the hard mock-guard gate proving it is a real flow, not a static screen.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['evidence'] = ['visual_smoke', 'a11y_or_reason', 'design_5d_review']; // no state_transition

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['state_transition_ok']);
        $this->assertContains('missing_evidence:state_transition', $result['blockers']);
        $this->assertContains('static_mock_not_proven_as_flow', $result['blockers']);
    }

    public function test_a11y_gate_is_satisfied_by_a_documented_reason(): void
    {
        // Contratos: "a11y ou razao" — the only gate with an escape hatch.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['evidence'] = ['visual_smoke', 'state_transition', 'design_5d_review']; // no a11y evidence
        $demo['a11y_reason'] = 'reduced-motion + focus order verified manually; reason logged';

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['evidence_checks']['a11y_or_reason']);
        $this->assertTrue($result['a11y_satisfied_by_reason']);
    }

    public function test_missing_onboarding_states_block_the_flow(): void
    {
        // Exemplos: onboarding com next/back/complete/settings. Fluxo: "clicar fluxo".
        $service = $this->service();
        $demo = $service->readySample();
        $demo['states'] = ['next', 'complete']; // no back / settings

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertContains('missing_state:back', $result['blockers']);
        $this->assertContains('missing_state:settings', $result['blockers']);
        $this->assertFalse($result['state_checks']['settings']);
        $this->assertTrue($result['state_checks']['next']);
    }

    public function test_published_claim_without_build_or_store_proof_is_a_claim_violation(): void
    {
        // Regras para IA: "Nao declarar app publicado sem build e store proof".
        $service = $this->service();
        $demo = $service->readySample();
        $demo['claims_published'] = true; // build_proof=false, store_proof=false by default

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_CLAIM_VIOLATION, $result['verdict']);
        $this->assertFalse($result['published_claim_allowed']);
        $this->assertContains('published_claim_without_build_proof', $result['claim_violations']);
        $this->assertContains('published_claim_without_store_proof', $result['claim_violations']);
        // Claim violation outranks otherwise-green gates: gates themselves still pass.
        $this->assertTrue($result['gates_passed']);
        $this->assertFalse($result['ready']);
    }

    public function test_published_claim_allowed_only_with_both_build_and_store_proof(): void
    {
        $service = $this->service();
        $demo = $service->readySample();
        $demo['claims_published'] = true;
        $demo['build_proof'] = true;
        $demo['store_proof'] = true;

        $decision = $service->publishedClaimDecision($demo);
        $this->assertTrue($decision['allowed']);
        $this->assertSame([], $decision['violations']);

        // And the full evaluation is then ready + publishable.
        $result = $service->evaluate($demo);
        $this->assertSame(AtlasProgrammingFrontendProductProofMobileAppOnboardingService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['published_claim_allowed']);
        $this->assertTrue($service->mayPublish($demo));

        // Drop just the store proof -> still a violation.
        $demo['store_proof'] = false;
        $this->assertFalse($service->publishedClaimDecision($demo)['allowed']);
        $this->assertFalse($service->mayPublish($demo));
    }
}
