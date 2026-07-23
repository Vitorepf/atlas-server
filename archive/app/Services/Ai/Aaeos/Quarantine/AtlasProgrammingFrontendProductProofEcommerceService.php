<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Ecommerce product-page product-proof gate evaluator.
 *
 * Pure, deterministic decider for the manifest doc
 * "Atlas Frontend Product Proof Ecommerce Product Page". Given the state of one
 * ecommerce product-detail demo (gallery, variants, cart, trust content, the
 * captured evidence and any checkout claim), it returns a single verdict —
 * `ready`, `blocked` or `claim_violation` — and the precise reasons, so a demo
 * can never be presented as proof while it skips a documented gate or claims a
 * functional checkout it did not test.
 *
 * Contract (from the doc):
 *   Contratos: viewports desktop + mobile; evidencias: visual_smoke,
 *     asset_provenance, anti_slop, performance_budget_or_reason.
 *   Fluxo: gerar demo -> registrar assets -> validar variantes/carrinho -> rodar gates.
 *   Regras para IA: "Nao declarar checkout real sem teste e escopo explicito".
 *   forbidden_changes: "Usar assets sem proveniencia ou declarar checkout
 *     funcional sem teste."
 *   Exemplos: produto com variantes de cor/tamanho, galeria e estado de carrinho.
 *   Escopo: manifest documental; nao contem storefront executavel.
 *
 * The service NEVER renders a page, hosts a demo, calls a provider or touches the
 * database. It evaluates a declared demo state and emits an auditable receipt;
 * callers decide whether the demo may be published as Atlas Frontend proof.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-ecommerce_product_page.md
 */
final class AtlasProgrammingFrontendProductProofEcommerceService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.frontend.product_proof_ecommerce_gate.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_READY = 'ready';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_CLAIM_VIOLATION = 'claim_violation';

    /**
     * Required viewports from the doc "Contratos": desktop and mobile.
     *
     * @var list<string>
     */
    public const REQUIRED_VIEWPORTS = ['desktop', 'mobile'];

    /**
     * The four evidence gates the doc "Contratos" mandates for this demo.
     * `performance_budget_or_reason` is satisfied by a budget OR a documented
     * reason — it is the only gate with an escape hatch, exactly as written.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'visual_smoke',
        'asset_provenance',
        'anti_slop',
        'performance_budget_or_reason',
    ];

    /**
     * Demo surfaces the doc "Exemplos" names for an ecommerce product page:
     * variantes de cor/tamanho, galeria e estado de carrinho. A faithful proof
     * must actually exercise these, otherwise it is not an ecommerce demo.
     *
     * @var list<string>
     */
    public const REQUIRED_SURFACES = [
        'media_gallery',
        'variant_selector',
        'cart_state',
        'trust_content',
    ];

    /**
     * Evaluate one ecommerce product-page demo against the documented contract.
     *
     * @param array<string,mixed> $demo
     *        viewports        : list<string>  rendered viewports (desktop/mobile/...)
     *        evidence         : list<string>  captured evidence ids
     *        surfaces         : list<string>  exercised UI surfaces (gallery/variant/cart/trust)
     *        performance_reason : string|null documented reason when no budget captured
     *        claims_checkout  : bool          does the demo claim a functional/real checkout?
     *        checkout_tested  : bool          is there a passing checkout test?
     *        checkout_scope_explicit : bool   is the checkout scope explicitly declared?
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluate(array $demo): array
    {
        $viewports = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['viewports'] ?? []);
        $evidence = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['evidence'] ?? []);
        $surfaces = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['surfaces'] ?? []);
        $performanceReason = AtlasAaeosValueNormalizer::stringOrNull($demo['performance_reason'] ?? null);

        $claimsCheckout = (bool) ($demo['claims_checkout'] ?? false);
        $checkoutTested = (bool) ($demo['checkout_tested'] ?? false);
        $checkoutScopeExplicit = (bool) ($demo['checkout_scope_explicit'] ?? false);

        $blockers = [];
        $claimViolations = [];

        // --- Fluxo step "validar variantes/carrinho": the named ecommerce
        // surfaces must be present, else it is not an ecommerce product demo.
        $missingSurfaces = array_values(array_diff(self::REQUIRED_SURFACES, $surfaces));
        foreach ($missingSurfaces as $surface) {
            $blockers[] = "missing_surface:{$surface}";
        }

        // --- Contratos viewports: both desktop AND mobile are required.
        $missingViewports = array_values(array_diff(self::REQUIRED_VIEWPORTS, $viewports));
        foreach ($missingViewports as $viewport) {
            $blockers[] = "missing_viewport:{$viewport}";
        }

        // --- Contratos evidence gates. performance_budget_or_reason can be
        // satisfied by either a captured budget evidence OR a documented reason.
        $evidenceChecks = [];
        foreach (self::REQUIRED_EVIDENCE as $gate) {
            $present = in_array($gate, $evidence, true);
            if ($gate === 'performance_budget_or_reason' && ! $present && $performanceReason !== null) {
                $present = true;
            }
            $evidenceChecks[$gate] = $present;
            if (! $present) {
                $blockers[] = "missing_evidence:{$gate}";
            }
        }

        // --- forbidden_changes invariant #1: "Usar assets sem proveniencia".
        // asset_provenance is the hard provenance gate; without it the demo can
        // never ship, because a placeholder may be mistaken for a real product
        // (doc Riscos). It is already counted above, but flagged explicitly as a
        // provenance violation so callers can surface the forbidden-change.
        $assetProvenanceOk = $evidenceChecks['asset_provenance'];
        if (! $assetProvenanceOk) {
            $blockers[] = 'forbidden_assets_without_provenance';
        }

        // --- Regras para IA + forbidden_changes invariant #2:
        // "Nao declarar checkout real sem teste e escopo explicito" /
        // "declarar checkout funcional sem teste". A checkout claim is ONLY
        // allowed when BOTH an explicit scope AND a passing checkout test exist.
        if ($claimsCheckout) {
            if (! $checkoutTested) {
                $claimViolations[] = 'checkout_claim_without_test';
            }
            if (! $checkoutScopeExplicit) {
                $claimViolations[] = 'checkout_claim_without_explicit_scope';
            }
        }

        $gatesPassed = $blockers === [];
        $checkoutClaimAllowed = $claimsCheckout && $claimViolations === [];

        // A forbidden claim is the most severe outcome: even a demo whose gates
        // would otherwise be green must not be published while it overstates a
        // checkout it never proved.
        if ($claimViolations !== []) {
            $verdict = self::VERDICT_CLAIM_VIOLATION;
        } elseif (! $gatesPassed) {
            $verdict = self::VERDICT_BLOCKED;
        } else {
            $verdict = self::VERDICT_READY;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'demo_id' => 'ecommerce_product_page',
            'verdict' => $verdict,
            'ready' => $verdict === self::VERDICT_READY,
            'gates_passed' => $gatesPassed,
            'viewport_checks' => $this->presenceMap(self::REQUIRED_VIEWPORTS, $viewports),
            'surface_checks' => $this->presenceMap(self::REQUIRED_SURFACES, $surfaces),
            'evidence_checks' => $evidenceChecks,
            'asset_provenance_ok' => $assetProvenanceOk,
            'performance_satisfied_by_reason' => ! in_array('performance_budget_or_reason', $evidence, true)
                && $performanceReason !== null,
            'claims_checkout' => $claimsCheckout,
            'checkout_claim_allowed' => $checkoutClaimAllowed,
            'claim_violations' => $claimViolations,
            'blockers' => $blockers,
            // Escopo de Implementacao: this manifest never authorizes a live
            // executable storefront — proof stays at demo + evidence level.
            'executable_storefront_authorized' => false,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this demo be published as Atlas Frontend
     * ecommerce product proof? (true only when gates pass and no claim is
     * overstated).
     *
     * @param array<string,mixed> $demo
     */
    public function mayPublish(array $demo): bool
    {
        return $this->evaluate($demo)['verdict'] === self::VERDICT_READY;
    }

    /**
     * Decide whether a functional/real checkout claim is permitted for the demo,
     * isolating the doc's headline "Regras para IA" rule for direct callers.
     *
     * @param array<string,mixed> $demo
     * @return array<string,mixed>
     */
    public function checkoutClaimDecision(array $demo): array
    {
        $claimsCheckout = (bool) ($demo['claims_checkout'] ?? false);
        $checkoutTested = (bool) ($demo['checkout_tested'] ?? false);
        $checkoutScopeExplicit = (bool) ($demo['checkout_scope_explicit'] ?? false);

        $violations = [];
        if ($claimsCheckout) {
            if (! $checkoutTested) {
                $violations[] = 'checkout_claim_without_test';
            }
            if (! $checkoutScopeExplicit) {
                $violations[] = 'checkout_claim_without_explicit_scope';
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'claims_checkout' => $claimsCheckout,
            'allowed' => $claimsCheckout && $violations === [],
            'requires' => ['checkout_tested', 'checkout_scope_explicit'],
            'violations' => $violations,
        ];
    }

    /**
     * A canonical fully-green ecommerce demo sample (all gates satisfied, no
     * checkout overclaim). Used by callers/tests as the baseline to mutate.
     *
     * @return array<string,mixed>
     */
    public function readySample(): array
    {
        return [
            'viewports' => self::REQUIRED_VIEWPORTS,
            'evidence' => self::REQUIRED_EVIDENCE,
            'surfaces' => self::REQUIRED_SURFACES,
            'performance_reason' => null,
            'claims_checkout' => false,
            'checkout_tested' => false,
            'checkout_scope_explicit' => false,
        ];
    }

    /**
     * @param list<string> $required
     * @param list<string> $present
     * @return array<string,bool>
     */
    private function presenceMap(array $required, array $present): array
    {
        $map = [];
        foreach ($required as $item) {
            $map[$item] = in_array($item, $present, true);
        }

        return $map;
    }

}
