<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi;

use App\Services\Ai\AutonomousEvolution\AtlasLoopEarnedAutonomyDecisionTrace;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRecursiveSelfImprovementGate;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\EarnedAutonomyGateService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Governed RSI · Part A (Build-Safety) · Self-Improvement Proposal Gate (seam).
 *
 * This is the live, mandatory entry seam for any Recursive Self-Improvement
 * proposal — a proposal where the loop wants to improve its OWN machinery. It
 * exists so RsiInvariantGuardService is REACHABLE (wired into a real path), not
 * an orphan service nobody calls. Every RSI proposal MUST be admitted through
 * `admit()`; there is no other route to promotion.
 *
 * The gate enforces the SAFETY-FIRST ordering demanded by the hard rule:
 *
 *   1. Run RsiInvariantGuardService FIRST. If it rejects (sacred path touched,
 *      invariant weakened, eligibility override, auto-canonize intent, or a
 *      malformed diff), the proposal is BLOCKED here and NEVER routed onward —
 *      it can never reach the operator's human gate, and it is never applied.
 *   2. Only a guard-PASSED proposal is routed to the operator's existing
 *      human gate (the canonical SelfDirectedEvolution curation inbox / operator
 *      decision path). This gate adds NO new merge / canon / apply authority: a
 *      passed proposal becomes `routed_to_human_gate` — proposal-only, awaiting
 *      an explicit operator decision. The gate NEVER auto-applies, NEVER
 *      auto-canonizes, NEVER calls a provider.
 *
 * Default-off: the RSI path is inert unless ATLAS_RSI_MODE is enabled (config
 * atlas.foundry.rsi.mode) OR an explicit $input override. With the flag off,
 * admit() returns status=skipped and routes nothing — the existing loop is
 * unchanged. This composes the on-main proposal-only substrate; it introduces no
 * new authority and reuses the human gate that already exists.
 */
final class RsiSelfImprovementProposalGate
{
    public const GATE_SCHEMA = 'atlas.foundry.rsi.self_improvement_proposal_gate.v1';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED_BY_INVARIANT = 'blocked_by_invariant_violation';

    public const STATUS_ROUTED_TO_HUMAN_GATE = 'routed_to_human_gate';

    /**
     * The ONLY status under which auto_applied may be true — and only after the
     * earned-autonomy composer authorized it under R0..R4 (kill armed, within
     * earned tier, drift-clean, red-team survived). Default-off: unreachable when
     * ATLAS_EARNED_AUTONOMY_MODE is off (the composer returns human_gate at R0).
     */
    public const STATUS_AUTO_APPLIED_EARNED = 'auto_applied_under_earned_autonomy';

    /** Test seam only: observe whether the invariant guard screen was actually invoked. */
    public static $screenObserver = null;

    public function __construct(
        private readonly RsiInvariantGuardService $guard,
        private readonly ?EarnedAutonomyGateService $earnedAutonomy = null,
        private readonly ?AtlasLoopRecursiveSelfImprovementGate $constitution = null,
        // OBSERVE-ONLY decision-trace recorder — nullable LAST arg (back-compat: legacy callers autowire null
        // ⇒ a default recorder). It NEVER actuates (no apply/merge/canonize) and NEVER influences routing.
        private readonly ?AtlasLoopEarnedAutonomyDecisionTrace $decisionTrace = null,
    ) {}

    /**
     * Admit an RSI proposal. Guard runs FIRST; routing happens only on PASS.
     *
     * @param  array<string,mixed>  $proposal  the RSI proposal; must carry a `diff` descriptor
     *                                          (changed_paths, optional removed_lines/added_lines)
     * @param  array<string,mixed>  $input      flag override seam (`rsi_mode_enabled` === true)
     * @return array<string,mixed>
     */
    public function admit(array $proposal, array $input = []): array
    {
        if (! $this->flagEnabled($input)) {
            return $this->emit(
                status: self::STATUS_SKIPPED,
                screening: null,
                detail: 'ATLAS_RSI_MODE off; RSI path inert, existing loop unchanged',
                routedToHumanGate: false,
            );
        }

        $diff = is_array($proposal['diff'] ?? null) ? $proposal['diff'] : [];
        foreach ((array) ($diff['changed_paths'] ?? []) as $path) {
            if (! is_string($path)) {
                continue;
            }
            $constitution = $this->constitution ?? new AtlasLoopRecursiveSelfImprovementGate;
            $verdict = $constitution->evaluate($path, AtlasLoopRecursiveSelfImprovementGate::KIND_IMPROVE);
            if (($verdict['status'] ?? null) === AtlasLoopRecursiveSelfImprovementGate::STATUS_REFUSED_PETREO) {
                return $this->emit(
                    status: self::STATUS_BLOCKED_BY_INVARIANT,
                    screening: [
                        'constitution_refused' => true,
                        'refused_path' => $path,
                        'reason' => 'constitution_forbids_editing_a_cert_organ',
                    ],
                    detail: 'constitution refused: self-edit to cert organ blocked before invariant guard',
                    routedToHumanGate: false,
                );
            }
        }

        // SAFETY FIRST: guard before anything else.
        if (is_callable(self::$screenObserver)) {
            (self::$screenObserver)($diff);
        }
        $screening = $this->guard->screen($diff);

        if (($screening['verdict'] ?? '') === RsiInvariantGuardService::VERDICT_REJECTED) {
            // Blocked here; NEVER routed to the human gate, NEVER applied.
            return $this->emit(
                status: self::STATUS_BLOCKED_BY_INVARIANT,
                screening: $screening,
                detail: 'invariant guard rejected the proposal; it cannot reach the human gate',
                routedToHumanGate: false,
            );
        }

        // PASSED: consult the earned-autonomy composer (non-invasive seam). With
        // ATLAS_EARNED_AUTONOMY_MODE off (and no $input override + kill disarmed by
        // default), decide() returns decision=human_gate at R0 — so this path is
        // byte-identical to the proposal-only behaviour shipped today.
        $ea = $this->earnedAutonomy ?? app(EarnedAutonomyGateService::class);
        $decision = $ea->decide($proposal, $input);

        // OBSERVE-ONLY: append the gate decision to the provider-safe audit JSONL (changed paths + decision
        // metadata ONLY, NEVER raw diff). Reached for BOTH guard-PASSED branches; NEVER on a guard reject
        // (those return above, before $decision). [] when the flag is OFF. Attached to the envelope AFTER
        // emit() so the gate_hash (and every existing field) is byte-identical — the trace rides outside the
        // hash and can never influence routing/status.
        $trace = ($this->decisionTrace ?? new AtlasLoopEarnedAutonomyDecisionTrace)
            ->record($decision, array_values((array) ($diff['changed_paths'] ?? [])));

        if (($decision['decision'] ?? '') === EarnedAutonomyGateService::DECISION_AUTO_APPLY
            && ($decision['auto_applied'] ?? false) === true) {
            $emitted = $this->emit(
                status: self::STATUS_AUTO_APPLIED_EARNED,
                screening: $screening,
                detail: 'guard passed; earned-autonomy auto-apply authorized (kill armed, within earned tier, drift-clean, red-team survived)',
                routedToHumanGate: false,
                earnedDecision: $decision,
            );
            $emitted['earned_autonomy_trace'] = $trace;

            return $emitted;
        }

        // Default / human_gate => byte-identical to today: proposal-only, routed to
        // the operator's existing human gate.
        $emitted = $this->emit(
            status: self::STATUS_ROUTED_TO_HUMAN_GATE,
            screening: $screening,
            detail: 'guard passed; proposal routed to operator human gate (proposal-only, no auto-apply)',
            routedToHumanGate: true,
            earnedDecision: $decision,
        );
        $emitted['earned_autonomy_trace'] = $trace;

        return $emitted;
    }

    /**
     * Default-off resolution mirroring the frontier-mode convention: config flag
     * defaults false; an explicit $input override must be === true.
     *
     * @param  array<string,mixed>  $input
     */
    private function flagEnabled(array $input): bool
    {
        $config = (bool) config('atlas.foundry.rsi.mode', false);
        $override = ($input['rsi_mode_enabled'] ?? null) === true;

        return $config || $override;
    }

    /**
     * @param  array<string,mixed>|null  $screening
     * @param  array<string,mixed>|null  $earnedDecision  the earned-autonomy composer decision (when consulted)
     * @return array<string,mixed>
     */
    private function emit(string $status, ?array $screening, string $detail, bool $routedToHumanGate, ?array $earnedDecision = null): array
    {
        $payload = [
            'schema_version' => self::GATE_SCHEMA,
            'status' => $status,
            'detail' => $detail,
            'routed_to_human_gate' => $routedToHumanGate,
            // auto_applied can become true in EXACTLY ONE place: a guard-PASSED
            // proposal that the earned-autonomy composer authorized under R0..R4.
            // Everything else stays proposal-only — and with the flag off the
            // composer always returns human_gate at R0, so this is false.
            'auto_applied' => $status === self::STATUS_AUTO_APPLIED_EARNED,
            'auto_canonized' => false,
            'proposal_only' => true,
            'provider_invoked' => false,
            'screening' => $screening,
            'earned_autonomy' => $earnedDecision,
        ];

        $payload['gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
