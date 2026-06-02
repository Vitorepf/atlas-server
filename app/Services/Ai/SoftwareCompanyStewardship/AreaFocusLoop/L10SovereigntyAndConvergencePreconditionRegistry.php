<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S147 — L10SovereigntyAndConvergencePreconditionRegistry (block: L10 Generative
 * Engineering Guard).
 *
 * Lists the two load-bearing, non-negotiable preconditions for L10 — operator
 * sovereignty and a PROVEN convergence bound — and certifies them against a
 * supplied context. These two preconditions are what separate "your sovereign
 * substrate of super-human engineering" from "an autonomous-by-itself system that
 * stopped being yours". The more generative (R1), strategic (R2), recursive (R3)
 * and fused (R4) the system becomes, the more load-bearing sovereignty and the
 * convergence bound become; therefore they PRECEDE every L10 pillar, they are not
 * consequences of it.
 *
 * The registry exposes, under list():
 *   - precondition_ids: the two preconditions, deterministically ordered;
 *   - statement: the immutable thesis ("bound and sovereignty precede every L10
 *     pillar");
 *   - protected_pillars: the four R1-R4 pillars these preconditions gate;
 *   - required_lower_evidence: the proven lower-ladder evidence the preconditions
 *     rest on (P5/L8 self-deception immunity, Q2/L9 proven invariants, plus the
 *     L8 and L9 certifications);
 *   - immovable=true and override_required=true.
 *
 * list() also yields a fail-closed certification verdict computed from the
 * context: a precondition that is not explicitly satisfied blocks. Missing
 * operator sovereignty blocks; a missing proven convergence bound blocks. The
 * action `system_as_source_of_ends` is permanently forbidden — the system may
 * articulate operator intent and propose strategy, but it is NEVER the source of
 * engineering ends.
 *
 * Pure: every returned field is computed from the method inputs and the constant
 * precondition definitions via the rules above. No I/O, DB, Eloquent, facade,
 * provider, git/Process, filesystem, clock or randomness. Identical inputs always
 * yield an identical result.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
 */
final class L10SovereigntyAndConvergencePreconditionRegistry
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.sovereignty_and_convergence_precondition.v1';

    /** The L10 phase this registry materializes. */
    public const PHASE = 'precondition';

    /** Stable identifier of the operator-sovereignty precondition. */
    public const PRECONDITION_SOVEREIGNTY = 'l10.operator_sovereignty';

    /** Stable identifier of the proven-convergence-bound precondition. */
    public const PRECONDITION_CONVERGENCE_BOUND = 'l10.proven_convergence_bound';

    /**
     * The immutable thesis: a proven convergence bound and operator sovereignty
     * precede every L10 pillar — they are pre-conditions, never consequences.
     */
    public const STATEMENT = 'A proven convergence bound and operator sovereignty are the two load-bearing preconditions for L10; bound and sovereignty precede every L10 pillar and can never be transcended.';

    /** The forbidden system action whose presence the registry must guarantee. */
    public const FORBIDDEN_SOURCE_OF_ENDS_ACTION = 'system_as_source_of_ends';

    /**
     * The two load-bearing preconditions, deterministically ordered (operator
     * sovereignty first, then the proven convergence bound) so the registry is
     * independent of authoring order.
     *
     * @var list<string>
     */
    private const PRECONDITION_IDS = [
        'l10.operator_sovereignty',
        'l10.proven_convergence_bound',
    ];

    /**
     * The four L10 pillars (R1-R4) that these preconditions gate. Bound and
     * sovereignty precede every one of them.
     *
     * @var list<string>
     */
    private const PROTECTED_PILLARS = [
        'r1_generative_engineering',
        'r2_long_horizon_strategy',
        'r3_bounded_recursive_self_improvement',
        'r4_operator_atlas_fusion',
    ];

    /**
     * Proven lower-ladder evidence the two preconditions rest on. A proven
     * convergence bound is unreadable without P5 (L8 self-deception immunity) and
     * Q2 (L9 proven invariants); sovereignty is unreadable without the real L8/L9
     * certifications below it. Sorted for determinism.
     *
     * @var list<string>
     */
    private const REQUIRED_LOWER_EVIDENCE = [
        'l8_p5_self_deception_immunity',
        'l8_transcendence_certification',
        'l9_q2_proven_invariants',
        'l9_sovereign_engineering_certification',
    ];

    /**
     * System actions the registry permanently forbids. `system_as_source_of_ends`
     * is always present; the others spell out the same prohibition (the system
     * never chooses, authors or owns the engineering ends/values/telos).
     *
     * @var list<string>
     */
    private const FORBIDDEN_SYSTEM_ACTIONS = [
        'authorize_own_engineering_ends',
        'choose_engineering_telos',
        'own_engineering_values',
        'system_as_source_of_ends',
    ];

    /**
     * List the two L10 preconditions and certify them against the given context.
     *
     * The two preconditions are NOT symmetric. Sovereignty is satisfied by the
     * operator being present; the convergence bound must be PROVEN — mere presence
     * never proves a bound, and recursion under an unproven bound is the definition
     * of runaway. Each precondition may be supplied at the top level under its key
     * (sovereignty => `operator_sovereignty`, bound => `convergence_bound`) and/or
     * nested under `preconditions[<key>]`; both locations are considered.
     *
     * Operator sovereignty is asserted when any considered signal is:
     *   - bool true; or
     *   - an array with truthy `present`/`satisfied`/`proven`.
     *
     * The convergence bound is asserted PROVEN only when a considered signal is:
     *   - bool true (the maximally explicit proof assertion); or
     *   - an array with `proven === true`.
     * A bound array carrying `present`/`satisfied` but no `proven === true` is
     * present-but-unproven and does NOT satisfy the precondition.
     *
     * Fail-closed dominates: for either precondition, any considered signal that is
     * explicit array `present === false` (sovereignty) or `proven === false`
     * (bound) blocks regardless of a truthy signal elsewhere. A precondition that
     * is absent, false or unproven blocks.
     *
     * @param  array<string,mixed>  $context
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     precondition_ids:list<string>,
     *     statement:string,
     *     protected_pillars:list<string>,
     *     required_lower_evidence:list<string>,
     *     forbidden_system_actions:list<string>,
     *     system_as_source_of_ends_forbidden:bool,
     *     immovable:bool,
     *     override_required:bool,
     *     sovereignty_present:bool,
     *     convergence_bound_proven:bool,
     *     certifiable:bool,
     *     blockers:list<string>
     * }
     */
    public function list(array $context = []): array
    {
        $sovereigntyPresent = $this->sovereigntyPresent($context);
        $convergenceBoundProven = $this->convergenceBoundProven($context);

        $blockers = [];
        if (! $sovereigntyPresent) {
            // Missing sovereignty precondition blocks: sovereignty is the only
            // thing separating a sovereign substrate from an autonomous-by-itself
            // system, so L10 cannot be certified without it.
            $blockers[] = 'sovereignty_precondition_missing';
        }
        if (! $convergenceBoundProven) {
            // Missing convergence_bound precondition blocks: recursive
            // self-improvement without a proven convergence bound is the
            // definition of runaway.
            $blockers[] = 'convergence_bound_precondition_missing';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'precondition_ids' => self::PRECONDITION_IDS,
            'statement' => self::STATEMENT,
            'protected_pillars' => self::PROTECTED_PILLARS,
            'required_lower_evidence' => self::REQUIRED_LOWER_EVIDENCE,
            'forbidden_system_actions' => self::FORBIDDEN_SYSTEM_ACTIONS,
            'system_as_source_of_ends_forbidden' => $this->forbids(self::FORBIDDEN_SOURCE_OF_ENDS_ACTION),
            'immovable' => true,
            'override_required' => true,
            'sovereignty_present' => $sovereigntyPresent,
            'convergence_bound_proven' => $convergenceBoundProven,
            'certifiable' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Whether a system action is forbidden by the registry. Computed by real
     * membership over the forbidden-action set (never a canned answer).
     */
    public function forbids(string $action): bool
    {
        return in_array($action, self::FORBIDDEN_SYSTEM_ACTIONS, true);
    }

    /**
     * Whether the context asserts operator sovereignty. Considers the top-level
     * `operator_sovereignty` key and `preconditions.operator_sovereignty`. Any
     * considered signal that is bare `true`, or an array with truthy
     * `present`/`satisfied`/`proven`, asserts it — unless any considered array
     * carries explicit `present === false`, which blocks fail-closed.
     *
     * @param  array<string,mixed>  $context
     */
    private function sovereigntyPresent(array $context): bool
    {
        $asserted = false;

        foreach ($this->signalsFor($context, 'operator_sovereignty') as $signal) {
            if (is_array($signal) && array_key_exists('present', $signal)
                && $signal['present'] === false) {
                return false;
            }

            if ($signal === true) {
                $asserted = true;

                continue;
            }

            if (is_array($signal) && (
                ($signal['present'] ?? false) === true
                || ($signal['satisfied'] ?? false) === true
                || ($signal['proven'] ?? false) === true
            )) {
                $asserted = true;
            }
        }

        return $asserted;
    }

    /**
     * Whether the context PROVES the convergence bound. Considers the top-level
     * `convergence_bound` key and `preconditions.convergence_bound`. A bound is
     * proven only by a bare `true` or an array with `proven === true`; presence or
     * satisfaction alone never proves a bound. Any considered array with explicit
     * `proven === false` blocks fail-closed, even against a truthy signal elsewhere
     * (recursion under an unproven bound is the definition of runaway).
     *
     * @param  array<string,mixed>  $context
     */
    private function convergenceBoundProven(array $context): bool
    {
        $proven = false;

        foreach ($this->signalsFor($context, 'convergence_bound') as $signal) {
            if (is_array($signal) && array_key_exists('proven', $signal)
                && $signal['proven'] === false) {
                return false;
            }

            if ($signal === true) {
                $proven = true;

                continue;
            }

            if (is_array($signal) && ($signal['proven'] ?? false) === true) {
                $proven = true;
            }
        }

        return $proven;
    }

    /**
     * Collect every supplied signal for a precondition key, in deterministic
     * order: the top-level `$context[$key]` first, then
     * `$context['preconditions'][$key]`. Keys that are absent contribute nothing,
     * so a single location still works and contradictory locations are both seen
     * (letting the fail-closed rule dominate).
     *
     * @param  array<string,mixed>  $context
     * @return list<mixed>
     */
    private function signalsFor(array $context, string $key): array
    {
        $signals = [];

        if (array_key_exists($key, $context)) {
            $signals[] = $context[$key];
        }

        $nested = $context['preconditions'] ?? null;
        if (is_array($nested) && array_key_exists($key, $nested)) {
            $signals[] = $nested[$key];
        }

        return $signals;
    }
}
