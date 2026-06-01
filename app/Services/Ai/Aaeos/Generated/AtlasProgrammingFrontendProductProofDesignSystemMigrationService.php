<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Design-system-migration product-proof gate evaluator.
 *
 * Pure, deterministic decider for the manifest doc
 * "Atlas Frontend Product Proof Design System Migration". Given the declared
 * state of one legacy-UI-to-tokenized-design-system migration demo (which Fluxo
 * steps were actually performed, which evidence was captured across the
 * required viewports, and whether the demo claims the migration is complete),
 * it returns a single verdict — `ready`, `blocked` or `claim_violation` — plus
 * the precise reasons, so a migration can never be presented as proof while it
 * skips a documented gate, and can never be DECLARED COMPLETE while it lacks the
 * scope, before/after diff, token migration or visual regression the doc
 * mandates.
 *
 * Contract (from the doc):
 *   Contratos: viewports desktop + tablet + mobile; evidencias:
 *     design_system_drift_check, anti_slop, visual_smoke, completion_hash.
 *   Fluxo: inventariar UI -> migrar tokens -> comparar before/after -> registrar gates.
 *   Regras para IA: "Nao declarar migracao completa sem escopo e regressao visual".
 *   forbidden_changes: "Declarar migracao concluida sem diff, tokens e regressao visual."
 *   Exemplos: tela legada convertida para componentes e tokens coerentes.
 *   Escopo: manifest documental; nao contem patch de migracao.
 *
 * The service NEVER renders a screen, runs a real migration, calls a provider or
 * touches the database. It evaluates a declared demo state and emits an
 * auditable receipt; callers decide whether the migration may be published as
 * Atlas Frontend proof and whether a "migration complete" claim is permitted.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
 */
final class AtlasProgrammingFrontendProductProofDesignSystemMigrationService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.frontend.product_proof_design_system_migration_gate.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_READY = 'ready';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_CLAIM_VIOLATION = 'claim_violation';

    /**
     * Required viewports from the doc "Contratos": desktop, tablet AND mobile.
     * A responsive migration must be proven on all three.
     *
     * @var list<string>
     */
    public const REQUIRED_VIEWPORTS = ['desktop', 'tablet', 'mobile'];

    /**
     * The four evidence gates the doc "Contratos" mandates for this demo. None
     * of them has an escape hatch — a migration proof must capture every one.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'design_system_drift_check',
        'anti_slop',
        'visual_smoke',
        'completion_hash',
    ];

    /**
     * Fluxo steps that must actually have happened for the artifact to BE a
     * design-system migration at all (doc "Fluxo" + "Exemplos": inventory the
     * legacy UI, migrate it onto tokens, then compare before/after). Without
     * these the demo is not a migration, regardless of evidence captured.
     *
     * @var list<string>
     */
    public const REQUIRED_FLOW_STEPS = [
        'ui_inventory',
        'token_migration',
        'before_after_comparison',
    ];

    /**
     * The conditions a "migration complete" claim requires. This fuses the
     * doc's "Regras para IA" (escopo + regressao visual) with the stricter
     * forbidden_changes invariant (diff + tokens + regressao visual). ALL four
     * must hold before a demo may declare the migration concluded.
     *
     * @var list<string>
     */
    public const COMPLETION_CLAIM_REQUIREMENTS = [
        'migration_scope_explicit',
        'before_after_diff',
        'token_migration',
        'visual_regression',
    ];

    /**
     * Evaluate one design-system-migration demo against the documented contract.
     *
     * @param array<string,mixed> $demo
     *        viewports                : list<string>  rendered viewports (desktop/tablet/mobile/...)
     *        evidence                 : list<string>  captured evidence ids
     *        flow_steps               : list<string>  Fluxo steps actually performed
     *        claims_migration_complete: bool          does the demo declare the migration complete?
     *        migration_scope_explicit : bool          is the migration scope explicitly declared?
     *        before_after_diff        : bool          is a before/after diff captured?
     *        token_migration          : bool          were legacy values migrated onto design tokens?
     *        visual_regression        : bool          did a visual regression check run?
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluate(array $demo): array
    {
        $viewports = $this->normalizeList($demo['viewports'] ?? []);
        $evidence = $this->normalizeList($demo['evidence'] ?? []);
        $flowSteps = $this->normalizeList($demo['flow_steps'] ?? []);

        $claimsComplete = (bool) ($demo['claims_migration_complete'] ?? false);
        $completionFlags = [
            'migration_scope_explicit' => (bool) ($demo['migration_scope_explicit'] ?? false),
            'before_after_diff' => (bool) ($demo['before_after_diff'] ?? false),
            'token_migration' => (bool) ($demo['token_migration'] ?? false),
            'visual_regression' => (bool) ($demo['visual_regression'] ?? false),
        ];

        $blockers = [];

        // --- Fluxo: the migration steps must actually have happened, else the
        // artifact is not a design-system migration (doc "Fluxo"/"Exemplos").
        $missingSteps = array_values(array_diff(self::REQUIRED_FLOW_STEPS, $flowSteps));
        foreach ($missingSteps as $step) {
            $blockers[] = "missing_flow_step:{$step}";
        }

        // --- Contratos viewports: desktop, tablet AND mobile are all required.
        $missingViewports = array_values(array_diff(self::REQUIRED_VIEWPORTS, $viewports));
        foreach ($missingViewports as $viewport) {
            $blockers[] = "missing_viewport:{$viewport}";
        }

        // --- Contratos evidence gates. All four are hard; none has an escape
        // hatch (unlike the ecommerce performance gate).
        $evidenceChecks = [];
        foreach (self::REQUIRED_EVIDENCE as $gate) {
            $present = in_array($gate, $evidence, true);
            $evidenceChecks[$gate] = $present;
            if (! $present) {
                $blockers[] = "missing_evidence:{$gate}";
            }
        }

        // --- Regras para IA + forbidden_changes:
        // "Nao declarar migracao completa sem escopo e regressao visual" /
        // "Declarar migracao concluida sem diff, tokens e regressao visual".
        // A completion claim is ONLY allowed when ALL of scope, diff, tokens and
        // visual regression hold. Any missing requirement is a claim violation.
        $claimViolations = [];
        if ($claimsComplete) {
            foreach (self::COMPLETION_CLAIM_REQUIREMENTS as $requirement) {
                if (! $completionFlags[$requirement]) {
                    $claimViolations[] = "completion_claim_without_{$requirement}";
                }
            }
        }

        $gatesPassed = $blockers === [];
        $completionClaimAllowed = $claimsComplete && $claimViolations === [];

        // A forbidden claim is the most severe outcome: even a demo whose gates
        // would otherwise be green must not be published while it declares a
        // migration complete it never fully proved (doc Riscos: refactor visual
        // amplo sem prova de regressao).
        if ($claimViolations !== []) {
            $verdict = self::VERDICT_CLAIM_VIOLATION;
        } elseif (! $gatesPassed) {
            $verdict = self::VERDICT_BLOCKED;
        } else {
            $verdict = self::VERDICT_READY;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'demo_id' => 'design_system_migration',
            'verdict' => $verdict,
            'ready' => $verdict === self::VERDICT_READY,
            'gates_passed' => $gatesPassed,
            'viewport_checks' => $this->presenceMap(self::REQUIRED_VIEWPORTS, $viewports),
            'flow_step_checks' => $this->presenceMap(self::REQUIRED_FLOW_STEPS, $flowSteps),
            'evidence_checks' => $evidenceChecks,
            'claims_migration_complete' => $claimsComplete,
            'completion_requirement_checks' => $completionFlags,
            'completion_claim_allowed' => $completionClaimAllowed,
            'claim_violations' => $claimViolations,
            'blockers' => $blockers,
            // Escopo de Implementacao: this manifest never authorizes a live
            // migration patch — proof stays at demo + evidence level.
            'migration_patch_authorized' => false,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this demo be published as Atlas Frontend
     * design-system-migration proof? (true only when every gate passes and no
     * completion claim is overstated).
     *
     * @param array<string,mixed> $demo
     */
    public function mayPublish(array $demo): bool
    {
        return $this->evaluate($demo)['verdict'] === self::VERDICT_READY;
    }

    /**
     * Decide whether a "migration complete" claim is permitted for the demo,
     * isolating the doc's headline "Regras para IA" / forbidden_changes rule for
     * direct callers.
     *
     * @param array<string,mixed> $demo
     * @return array<string,mixed>
     */
    public function completionClaimDecision(array $demo): array
    {
        $claimsComplete = (bool) ($demo['claims_migration_complete'] ?? false);
        $completionFlags = [
            'migration_scope_explicit' => (bool) ($demo['migration_scope_explicit'] ?? false),
            'before_after_diff' => (bool) ($demo['before_after_diff'] ?? false),
            'token_migration' => (bool) ($demo['token_migration'] ?? false),
            'visual_regression' => (bool) ($demo['visual_regression'] ?? false),
        ];

        $violations = [];
        if ($claimsComplete) {
            foreach (self::COMPLETION_CLAIM_REQUIREMENTS as $requirement) {
                if (! $completionFlags[$requirement]) {
                    $violations[] = "completion_claim_without_{$requirement}";
                }
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'claims_migration_complete' => $claimsComplete,
            'allowed' => $claimsComplete && $violations === [],
            'requires' => self::COMPLETION_CLAIM_REQUIREMENTS,
            'violations' => $violations,
        ];
    }

    /**
     * A canonical fully-green migration demo sample (all gates satisfied, all
     * Fluxo steps done, no completion overclaim). Used by callers/tests as the
     * baseline to mutate.
     *
     * @return array<string,mixed>
     */
    public function readySample(): array
    {
        return [
            'viewports' => self::REQUIRED_VIEWPORTS,
            'evidence' => self::REQUIRED_EVIDENCE,
            'flow_steps' => self::REQUIRED_FLOW_STEPS,
            'claims_migration_complete' => false,
            'migration_scope_explicit' => false,
            'before_after_diff' => false,
            'token_migration' => false,
            'visual_regression' => false,
        ];
    }

    /**
     * A canonical migration demo that DOES legitimately claim completion: every
     * gate green, every Fluxo step done, and all four completion requirements
     * satisfied. The one declared state in which "migration complete" is
     * permitted by the doc.
     *
     * @return array<string,mixed>
     */
    public function completedSample(): array
    {
        return [
            'viewports' => self::REQUIRED_VIEWPORTS,
            'evidence' => self::REQUIRED_EVIDENCE,
            'flow_steps' => self::REQUIRED_FLOW_STEPS,
            'claims_migration_complete' => true,
            'migration_scope_explicit' => true,
            'before_after_diff' => true,
            'token_migration' => true,
            'visual_regression' => true,
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

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = strtolower(trim($item));
            }
        }

        return array_values(array_unique($clean));
    }
}
