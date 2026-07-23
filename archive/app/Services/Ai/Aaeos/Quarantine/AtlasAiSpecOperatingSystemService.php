<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas AI Spec Operating System gate.
 *
 * Pure, deterministic enforcement of the top-level SDD ("spec-driven
 * development") contract: ordinary intent must become governed engineering
 * artifacts before risky execution. This decider does NOT run the pipeline
 * (the executable wiring lives in App\Services\Ai\Programming\Sdd\*). It
 * enforces the doc's distinctive invariants as an auditable verdict so a step
 * can never silently skip a Hard Law, run the canonical stages out of order,
 * blindly obey wording that contradicts the design system, or one-shot execute
 * without high confidence and available gates.
 *
 * Concrete rules grounded in the doc:
 *   - "Hard Laws"      → seven invariants. Risky implementation requires an
 *     operational spec; a spec requires context; execution requires a Decision
 *     Receipt; a result requires evidence; learning that changes critical
 *     behavior requires a proposal/review; no `.atlas` local tree may outrank
 *     canonical docs/APs/Kernel/receipts; no blind obedience to user wording
 *     when design system / security / business rules contradict it.
 *   - "Canonical Flow" → the sixteen ordered stages from "Surface receives
 *     intent" to "Self-Improvement proposal". A trace must keep canonical order
 *     and may not place execution before its Decision Receipt.
 *   - "Green Save Button" → when user wording conflicts with the design system,
 *     Atlas keeps the design token and records the divergence; it does not emit
 *     the literal request.
 *   - frontmatter decision → one-shot execution is allowed only when context
 *     confidence is high AND quality gates are available.
 *
 * The service NEVER mutates code, calls a provider, runs a gate or touches the
 * database. It emits the verdict plus an audit receipt; callers decide whether
 * to admit the step, route it to clarification/review or block it.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
 */
final class AtlasAiSpecOperatingSystemService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.spec_operating_system.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_REVIEW = 'allow_with_review';
    public const VERDICT_BLOCK = 'block';

    /**
     * "Canonical Flow" — the sixteen ordered stages. Index is the canonical
     * rank; a valid trace is a non-decreasing walk through these stages.
     *
     * @var list<string>
     */
    public const CANONICAL_FLOW = [
        'surface_intent',
        'operation_envelope',
        'intent_domain_risk_routing',
        'context_discovery',
        'business_context',
        'spec_compiler',
        'spec_critic_ambiguity_gate',
        'plan_compiler',
        'task_compiler',
        'atlas_decide',
        'decision_receipt',
        'execution_harness',
        'quality_gates',
        'evidence_ledger',
        'spec_drift_detector',
        'self_improvement_proposal',
    ];

    /** Canonical-flow stage that authorizes execution. */
    public const STAGE_DECISION_RECEIPT = 'decision_receipt';

    /** Canonical-flow stage that performs execution. */
    public const STAGE_EXECUTION = 'execution_harness';

    /**
     * "Hard Laws" — the seven invariants, in doc order. Each maps to a guard in
     * evaluateOperation(); the key is the stable reason code emitted on breach.
     *
     * @var list<string>
     */
    public const HARD_LAWS = [
        'risky_impl_requires_spec',
        'spec_requires_context',
        'execution_requires_receipt',
        'result_requires_evidence',
        'critical_learning_requires_review',
        'atlas_tree_cannot_outrank_canonical',
        'no_blind_obedience_vs_design_or_security',
    ];

    /**
     * Evaluate a proposed SDD step against the seven Hard Laws.
     *
     * Inputs (all optional; fail-closed where the doc says a precondition is
     * mandatory):
     *   risky               : bool  is the implementation risky?
     *   has_operational_spec: bool  is an operational spec present?
     *   spec_present        : bool  is any spec being produced/used this step?
     *   has_context         : bool  was context discovered/grounded?
     *   executing           : bool  does this step write code / run execution?
     *   has_decision_receipt: bool  is a valid Decision Receipt in hand?
     *   produced_result     : bool  did the step produce a user-facing result?
     *   has_evidence        : bool  is evidence recorded for that result?
     *   learning_changes_critical_behavior : bool
     *   learning_reviewed   : bool  proposal/review attached to that learning?
     *   atlas_tree_overrides_canonical     : bool  does a local `.atlas` tree
     *                                              claim authority over canon?
     *   contradicts_design_or_security     : bool  does the wording fight the
     *                                              design system / security /
     *                                              business rules?
     *   divergence_recorded : bool  was the divergence recorded instead of
     *                                blindly obeyed?
     *
     * @param array<string,mixed> $step
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluateOperation(array $step): array
    {
        $risky = $this->flag($step, 'risky');
        $hasSpec = $this->flag($step, 'has_operational_spec');
        $specPresent = $this->flag($step, 'spec_present');
        $hasContext = $this->flag($step, 'has_context');
        $executing = $this->flag($step, 'executing');
        $hasReceipt = $this->flag($step, 'has_decision_receipt');
        $producedResult = $this->flag($step, 'produced_result');
        $hasEvidence = $this->flag($step, 'has_evidence');
        $criticalLearning = $this->flag($step, 'learning_changes_critical_behavior');
        $learningReviewed = $this->flag($step, 'learning_reviewed');
        $treeOverrides = $this->flag($step, 'atlas_tree_overrides_canonical');
        $contradicts = $this->flag($step, 'contradicts_design_or_security');
        $divergenceRecorded = $this->flag($step, 'divergence_recorded');

        $violations = [];

        // Hard Law 1 — "No risky implementation without operational spec."
        if ($risky && ! $hasSpec) {
            $violations[] = 'risky_impl_requires_spec';
        }

        // Hard Law 2 — "No operational spec without context."
        if ($specPresent && ! $hasContext) {
            $violations[] = 'spec_requires_context';
        }

        // Hard Law 3 — "No execution without Decision Receipt."
        if ($executing && ! $hasReceipt) {
            $violations[] = 'execution_requires_receipt';
        }

        // Hard Law 4 — "No result without evidence."
        if ($producedResult && ! $hasEvidence) {
            $violations[] = 'result_requires_evidence';
        }

        // Hard Law 5 — "No learning that changes critical behavior without
        // proposal/review."
        if ($criticalLearning && ! $learningReviewed) {
            $violations[] = 'critical_learning_requires_review';
        }

        // Hard Law 6 — "No `.atlas` local tree can outrank canonical docs, APs,
        // Kernel or receipts."
        if ($treeOverrides) {
            $violations[] = 'atlas_tree_cannot_outrank_canonical';
        }

        // Hard Law 7 — "No blind obedience to user wording when design system,
        // security or business rules contradict it." Obeying is only safe when
        // the divergence was recorded (the design token preserved).
        if ($contradicts && ! $divergenceRecorded) {
            $violations[] = 'no_blind_obedience_vs_design_or_security';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'hard_laws',
            'verdict' => $violations === [] ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'violations' => $violations,
            'laws_enforced' => self::HARD_LAWS,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate over evaluateOperation().
     *
     * @param array<string,mixed> $step
     */
    public function operationAllowed(array $step): bool
    {
        return $this->evaluateOperation($step)['verdict'] === self::VERDICT_ALLOW;
    }

    /**
     * "Canonical Flow" — validate that an observed stage trace respects the
     * canonical order. A trace is valid when (a) every stage is canonical,
     * (b) the stages never go backwards (non-decreasing canonical rank), and
     * (c) execution never appears before its Decision Receipt.
     *
     * @param list<string> $trace observed stage codes, in the order they ran
     * @return array<string,mixed>
     */
    public function validateFlowOrder(array $trace): array
    {
        $stages = AtlasAaeosStringListNormalizer::lowerTrimmedStrings($trace);

        $unknown = [];
        $outOfOrder = [];
        $lastRank = -1;
        $receiptIndex = null;
        $executionIndex = null;

        foreach ($stages as $position => $stage) {
            $rank = array_search($stage, self::CANONICAL_FLOW, true);
            if ($rank === false) {
                $unknown[] = $stage;

                continue;
            }
            if ($rank < $lastRank) {
                $outOfOrder[] = $stage;
            }
            $lastRank = max($lastRank, $rank);

            if ($stage === self::STAGE_DECISION_RECEIPT && $receiptIndex === null) {
                $receiptIndex = $position;
            }
            if ($stage === self::STAGE_EXECUTION && $executionIndex === null) {
                $executionIndex = $position;
            }
        }

        // Hard Law 3 reflected on the trace: execution may not precede receipt.
        $executionBeforeReceipt = $executionIndex !== null
            && ($receiptIndex === null || $executionIndex < $receiptIndex);

        $valid = $unknown === [] && $outOfOrder === [] && ! $executionBeforeReceipt;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'canonical_flow',
            'verdict' => $valid ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'valid' => $valid,
            'unknown_stages' => $unknown,
            'out_of_order_stages' => $outOfOrder,
            'execution_before_receipt' => $executionBeforeReceipt,
            'auditable' => true,
        ];
    }

    /**
     * "Green Save Button" — resolve a UI directive against the design system.
     *
     * The doc forbids blindly emitting the literal request when the design
     * system disagrees. When the requested token differs from the design-system
     * token for the action, Atlas uses the design token and records the
     * divergence; otherwise it honors the request directly.
     *
     * @param array<string,mixed> $directive
     *        requested_token    : string  what the user literally asked for
     *        design_system_token: string  the token the design system mandates
     *        action             : string  e.g. 'save' (for the receipt note)
     * @return array<string,mixed>
     */
    public function resolveDesignDirective(array $directive): array
    {
        $requested = AtlasAaeosValueNormalizer::lowerString($directive['requested_token'] ?? null);
        $designToken = AtlasAaeosValueNormalizer::lowerString($directive['design_system_token'] ?? null);
        $action = AtlasAaeosValueNormalizer::lowerString($directive['action'] ?? null);

        // Unknown design token => nothing to reconcile against; honor request.
        $conflict = $designToken !== '' && $requested !== '' && $requested !== $designToken;

        $applied = $conflict ? $designToken : ($requested !== '' ? $requested : $designToken);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'design_directive',
            'conflict' => $conflict,
            'applied_token' => $applied,
            'requested_token' => $requested,
            'design_system_token' => $designToken,
            'blindly_obeyed' => false,
            'divergence_recorded' => $conflict,
            'divergence_note' => $conflict
                ? sprintf(
                    'User asked %s; project policy uses %s for %s actions. Atlas preserved design-system consistency.',
                    $requested,
                    $designToken,
                    $action !== '' ? $action : 'this',
                )
                : null,
            'auditable' => true,
        ];
    }

    /**
     * frontmatter decision — "One-shot execution is allowed only when context
     * confidence is high and gates are available." Anything short of that is
     * not a hard failure of the request; it routes to the governed (staged)
     * path, so the verdict is `allow_with_review` rather than `block`.
     *
     * @param array<string,mixed> $context
     *        confidence       : string  'high'|'medium'|'low' (default low)
     *        gates_available  : bool    are quality gates available?
     * @return array<string,mixed>
     */
    public function evaluateOneShotEligibility(array $context): array
    {
        $confidence = AtlasAaeosValueNormalizer::lowerString($context['confidence'] ?? null);
        $gatesAvailable = $this->flag($context, 'gates_available');

        $reasons = [];
        if ($confidence !== 'high') {
            $reasons[] = $confidence === ''
                ? 'confidence_missing'
                : "confidence_not_high:{$confidence}";
        }
        if (! $gatesAvailable) {
            $reasons[] = 'gates_unavailable';
        }

        $eligible = $reasons === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'one_shot_eligibility',
            'verdict' => $eligible ? self::VERDICT_ALLOW : self::VERDICT_REVIEW,
            'one_shot_allowed' => $eligible,
            'route' => $eligible ? 'one_shot' : 'staged_pipeline',
            'block_reasons' => $reasons,
            'auditable' => true,
        ];
    }

    private function flag(array $source, string $key): bool
    {
        return ($source[$key] ?? false) === true;
    }

}
