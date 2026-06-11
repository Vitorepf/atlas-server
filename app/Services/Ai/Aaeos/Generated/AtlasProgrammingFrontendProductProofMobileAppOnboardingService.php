<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Mobile app-onboarding product-proof gate evaluator.
 *
 * Pure, deterministic decider for the manifest doc
 * "Atlas Frontend Product Proof Mobile App Onboarding". Given the declared state
 * of one mobile onboarding demo (the rendered viewport, the exercised onboarding
 * states, the captured evidence and any "published app" claim), it returns a
 * single verdict — `ready`, `blocked` or `claim_violation` — plus the precise
 * reasons, so a demo can never be presented as proof while it skips a documented
 * gate, ships as a static mock instead of a real state flow, or claims a
 * published/native app it never built and shipped to a store.
 *
 * Contract (from the doc):
 *   Contratos: viewport mobile; evidencias: visual_smoke, state_transition,
 *     a11y_or_reason, design_5d_review.
 *   Fluxo: gerar prototipo -> clicar fluxo -> validar acessibilidade -> registrar review.
 *   Regras para IA: "Nao declarar app publicado sem build e store proof".
 *   forbidden_changes: "Declarar app nativo ou publicado sem build/prova correspondente."
 *   Exemplos: onboarding com next/back/complete/settings.
 *   Riscos: "Confundir mock estatico com fluxo mobile."
 *   Escopo: manifest documental; nao contem app executavel.
 *
 * The service NEVER renders a screen, hosts a demo, calls a provider or touches
 * the database. It evaluates a declared demo state and emits an auditable
 * receipt; callers decide whether the demo may be published as Atlas Frontend
 * mobile onboarding proof.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-mobile_app_onboarding.md
 */
final class AtlasProgrammingFrontendProductProofMobileAppOnboardingService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.frontend.product_proof_mobile_app_onboarding_gate.v1';

    /** Stable demo id this gate evaluates (matches the frontend proof catalog). */
    public const DEMO_ID = 'mobile_app_onboarding';

    /** Canonical verdicts (closed set). */
    public const VERDICT_READY = 'ready';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_CLAIM_VIOLATION = 'claim_violation';

    /**
     * Required viewport from the doc "Contratos": mobile only. A mobile
     * onboarding proof rendered on anything else is not the documented surface.
     *
     * @var list<string>
     */
    public const REQUIRED_VIEWPORTS = ['mobile'];

    /**
     * The four evidence gates the doc "Contratos" mandates for this demo.
     * `a11y_or_reason` is satisfied by an accessibility check OR a documented
     * reason — it is the only gate with an escape hatch, exactly as written
     * ("a11y ou razao").
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'visual_smoke',
        'state_transition',
        'a11y_or_reason',
        'design_5d_review',
    ];

    /**
     * The gate whose escape hatch is a documented reason rather than evidence.
     */
    public const REASON_BACKED_GATE = 'a11y_or_reason';

    /**
     * Onboarding states the doc "Exemplos" names: next / back / complete /
     * settings. A faithful proof must actually exercise these transitions,
     * otherwise it is a static mock and not a mobile flow (doc Riscos).
     *
     * @var list<string>
     */
    public const REQUIRED_STATES = ['next', 'back', 'complete', 'settings'];

    /**
     * Evaluate one mobile onboarding demo against the documented contract.
     *
     * @param array<string,mixed> $demo
     *        viewports        : list<string>  rendered viewports (must contain mobile)
     *        evidence         : list<string>  captured evidence ids
     *        states           : list<string>  exercised onboarding states (next/back/complete/settings)
     *        a11y_reason      : string|null   documented reason when no a11y check captured
     *        claims_published : bool          does the demo claim a published/native app?
     *        build_proof      : bool          is there a real build artifact/proof?
     *        store_proof      : bool          is there a store-publication proof?
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluate(array $demo): array
    {
        $viewports = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['viewports'] ?? []);
        $evidence = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['evidence'] ?? []);
        $states = AtlasAaeosStringListNormalizer::uniqueLowerTrimmedStrings($demo['states'] ?? []);
        $a11yReason = $this->normalizeString($demo['a11y_reason'] ?? null);

        $claimsPublished = (bool) ($demo['claims_published'] ?? false);
        $buildProof = (bool) ($demo['build_proof'] ?? false);
        $storeProof = (bool) ($demo['store_proof'] ?? false);

        $blockers = [];
        $claimViolations = [];

        // --- Fluxo step "clicar fluxo": the named onboarding states must be
        // present, else there is no real flow to click through.
        $missingStates = array_values(array_diff(self::REQUIRED_STATES, $states));
        foreach ($missingStates as $state) {
            $blockers[] = "missing_state:{$state}";
        }

        // --- Contratos viewport: mobile is required.
        $missingViewports = array_values(array_diff(self::REQUIRED_VIEWPORTS, $viewports));
        foreach ($missingViewports as $viewport) {
            $blockers[] = "missing_viewport:{$viewport}";
        }

        // --- Contratos evidence gates. a11y_or_reason can be satisfied by either
        // a captured a11y check OR a documented reason ("a11y ou razao").
        $evidenceChecks = [];
        foreach (self::REQUIRED_EVIDENCE as $gate) {
            $present = in_array($gate, $evidence, true);
            if ($gate === self::REASON_BACKED_GATE && ! $present && $a11yReason !== null) {
                $present = true;
            }
            $evidenceChecks[$gate] = $present;
            if (! $present) {
                $blockers[] = "missing_evidence:{$gate}";
            }
        }

        // --- Riscos: "Confundir mock estatico com fluxo mobile". state_transition
        // is the hard mock-guard gate; without it a static mock could be mistaken
        // for a real onboarding flow. It is already counted above, but flagged
        // explicitly so callers can surface the specific risk.
        $stateTransitionOk = $evidenceChecks['state_transition'];
        if (! $stateTransitionOk) {
            $blockers[] = 'static_mock_not_proven_as_flow';
        }

        // --- Regras para IA + forbidden_changes:
        // "Nao declarar app publicado sem build e store proof" /
        // "Declarar app nativo ou publicado sem build/prova correspondente."
        // A published-app claim is ONLY allowed when BOTH a build proof AND a
        // store proof exist.
        if ($claimsPublished) {
            if (! $buildProof) {
                $claimViolations[] = 'published_claim_without_build_proof';
            }
            if (! $storeProof) {
                $claimViolations[] = 'published_claim_without_store_proof';
            }
        }

        $gatesPassed = $blockers === [];
        $publishedClaimAllowed = $claimsPublished && $claimViolations === [];

        // A forbidden claim is the most severe outcome: even a demo whose gates
        // would otherwise be green must not be presented while it overstates a
        // published/native app it never built and shipped.
        if ($claimViolations !== []) {
            $verdict = self::VERDICT_CLAIM_VIOLATION;
        } elseif (! $gatesPassed) {
            $verdict = self::VERDICT_BLOCKED;
        } else {
            $verdict = self::VERDICT_READY;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'demo_id' => self::DEMO_ID,
            'verdict' => $verdict,
            'ready' => $verdict === self::VERDICT_READY,
            'gates_passed' => $gatesPassed,
            'viewport_checks' => $this->presenceMap(self::REQUIRED_VIEWPORTS, $viewports),
            'state_checks' => $this->presenceMap(self::REQUIRED_STATES, $states),
            'evidence_checks' => $evidenceChecks,
            'state_transition_ok' => $stateTransitionOk,
            'a11y_satisfied_by_reason' => ! in_array(self::REASON_BACKED_GATE, $evidence, true)
                && $a11yReason !== null,
            'claims_published' => $claimsPublished,
            'published_claim_allowed' => $publishedClaimAllowed,
            'claim_violations' => $claimViolations,
            'blockers' => $blockers,
            // Escopo de Implementacao: this manifest never authorizes a live
            // executable / native app — proof stays at demo + evidence level.
            'executable_app_authorized' => false,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this demo be published as Atlas Frontend mobile
     * onboarding proof? (true only when gates pass and no claim is overstated).
     *
     * @param array<string,mixed> $demo
     */
    public function mayPublish(array $demo): bool
    {
        return $this->evaluate($demo)['verdict'] === self::VERDICT_READY;
    }

    /**
     * Decide whether a published/native-app claim is permitted for the demo,
     * isolating the doc's headline "Regras para IA" rule for direct callers.
     *
     * @param array<string,mixed> $demo
     * @return array<string,mixed>
     */
    public function publishedClaimDecision(array $demo): array
    {
        $claimsPublished = (bool) ($demo['claims_published'] ?? false);
        $buildProof = (bool) ($demo['build_proof'] ?? false);
        $storeProof = (bool) ($demo['store_proof'] ?? false);

        $violations = [];
        if ($claimsPublished) {
            if (! $buildProof) {
                $violations[] = 'published_claim_without_build_proof';
            }
            if (! $storeProof) {
                $violations[] = 'published_claim_without_store_proof';
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'claims_published' => $claimsPublished,
            'allowed' => $claimsPublished && $violations === [],
            'requires' => ['build_proof', 'store_proof'],
            'violations' => $violations,
        ];
    }

    /**
     * A canonical fully-green mobile onboarding demo sample (all gates satisfied,
     * no published overclaim). Used by callers/tests as the baseline to mutate.
     *
     * @return array<string,mixed>
     */
    public function readySample(): array
    {
        return [
            'viewports' => self::REQUIRED_VIEWPORTS,
            'evidence' => self::REQUIRED_EVIDENCE,
            'states' => self::REQUIRED_STATES,
            'a11y_reason' => null,
            'claims_published' => false,
            'build_proof' => false,
            'store_proof' => false,
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

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
