<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofEcommerceService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo" and headline "Regras para IA" of the
 * ecommerce product-page manifest. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-ecommerce_product_page.md
 */
class AtlasProgrammingFrontendProductProofEcommerceTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendProductProofEcommerceService
    {
        return new AtlasProgrammingFrontendProductProofEcommerceService;
    }

    public function test_canonical_sample_passes_every_documented_gate(): void
    {
        $service = $this->service();
        $result = $service->evaluate($service->readySample());

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['ready']);
        $this->assertTrue($result['gates_passed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['claim_violations']);
        // Escopo de Implementacao: a manifest never authorizes an executable storefront.
        $this->assertFalse($result['executable_storefront_authorized']);
    }

    public function test_missing_mobile_viewport_blocks_proof(): void
    {
        // Contratos: viewports desktop AND mobile are both required.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['viewports'] = ['desktop'];

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['gates_passed']);
        $this->assertContains('missing_viewport:mobile', $result['blockers']);
        $this->assertFalse($result['viewport_checks']['mobile']);
        $this->assertTrue($result['viewport_checks']['desktop']);
    }

    public function test_assets_without_provenance_is_a_forbidden_change_and_blocks(): void
    {
        // forbidden_changes: "Usar assets sem proveniencia ..." + Riscos:
        // asset placeholder parecer produto real.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['evidence'] = ['visual_smoke', 'anti_slop', 'performance_budget_or_reason']; // no asset_provenance

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['asset_provenance_ok']);
        $this->assertContains('missing_evidence:asset_provenance', $result['blockers']);
        $this->assertContains('forbidden_assets_without_provenance', $result['blockers']);
    }

    public function test_performance_budget_gate_is_satisfied_by_a_documented_reason(): void
    {
        // Contratos: "performance budget OR razao" — the only gate with an escape hatch.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['evidence'] = ['visual_smoke', 'asset_provenance', 'anti_slop']; // no performance evidence
        $demo['performance_reason'] = 'asset set is below budget; static demo, reason logged';

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['evidence_checks']['performance_budget_or_reason']);
        $this->assertTrue($result['performance_satisfied_by_reason']);
    }

    public function test_checkout_claim_without_test_or_scope_is_a_claim_violation(): void
    {
        // Regras para IA: "Nao declarar checkout real sem teste e escopo explicito".
        $service = $this->service();
        $demo = $service->readySample();
        $demo['claims_checkout'] = true; // tested=false, scope=false by default

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_CLAIM_VIOLATION, $result['verdict']);
        $this->assertFalse($result['checkout_claim_allowed']);
        $this->assertContains('checkout_claim_without_test', $result['claim_violations']);
        $this->assertContains('checkout_claim_without_explicit_scope', $result['claim_violations']);
        // Claim violation outranks otherwise-green gates: gates themselves still pass.
        $this->assertTrue($result['gates_passed']);
        $this->assertFalse($result['ready']);
    }

    public function test_checkout_claim_allowed_only_with_both_test_and_explicit_scope(): void
    {
        $service = $this->service();
        $demo = $service->readySample();
        $demo['claims_checkout'] = true;
        $demo['checkout_tested'] = true;
        $demo['checkout_scope_explicit'] = true;

        $decision = $service->checkoutClaimDecision($demo);
        $this->assertTrue($decision['allowed']);
        $this->assertSame([], $decision['violations']);

        // And the full evaluation is then ready + publishable.
        $result = $service->evaluate($demo);
        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['checkout_claim_allowed']);
        $this->assertTrue($service->mayPublish($demo));

        // Drop just the explicit scope -> still a violation.
        $demo['checkout_scope_explicit'] = false;
        $this->assertFalse($service->checkoutClaimDecision($demo)['allowed']);
        $this->assertFalse($service->mayPublish($demo));
    }

    public function test_missing_cart_and_variant_surfaces_block_ecommerce_proof(): void
    {
        // Fluxo: "validar variantes/carrinho" — Exemplos name variants + cart state.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['surfaces'] = ['media_gallery', 'trust_content']; // no variant_selector / cart_state

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofEcommerceService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertContains('missing_surface:variant_selector', $result['blockers']);
        $this->assertContains('missing_surface:cart_state', $result['blockers']);
        $this->assertFalse($result['surface_checks']['cart_state']);
    }
}
