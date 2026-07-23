<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Executable runtime for the Atlas AI Skill System governance doc.
 *
 * Implements three concrete, deterministic contracts stated verbatim in the doc:
 *
 *  1. Fronteira (boundary): classify an Atlas concept as one of
 *     skill | workflow | agent | provider | tool_runtime, per the rule table —
 *     so callers never confuse a skill (versioned operational contract) with an
 *     agent (temporary role with no authority of its own).
 *
 *  2. Lifecycle: the draft -> candidate -> ready -> default -> deprecated state
 *     machine with explicit entry gates. The hard rules enforced here:
 *       - You may NOT reach `ready` without evidence of gain vs baseline.
 *       - You may NOT reach `default` without `ready` PLUS human review AND
 *         safety approval ("Skill sem eval/evidence nao vira default").
 *       - A skill may always regress to `deprecated` (piorou/conflitou/substituida).
 *
 *  3. Invariantes: conflict between skills resolves in a fixed authority order —
 *     Output Governor -> Kernel policy -> flow owner (nessa ordem); and an
 *     external skill is software dependency-class (review + pinning + rollback).
 *
 * Pure: no DB, no provider calls, no filesystem. Every method returns a typed
 * array describing a decision so a command / caller can serialize it.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-skill-system.md
 */
final class AtlasAiSkillSystemService
{
    /** Ordered lifecycle states (index === progression rank). */
    public const STATE_DRAFT = 'draft';

    public const STATE_CANDIDATE = 'candidate';

    public const STATE_READY = 'ready';

    public const STATE_DEFAULT = 'default';

    public const STATE_DEPRECATED = 'deprecated';

    /**
     * Forward progression order. `deprecated` is intentionally NOT in this list:
     * it is a terminal regression reachable from any active state, not a rank.
     *
     * @var list<string>
     */
    private const PROGRESSION = [
        self::STATE_DRAFT,
        self::STATE_CANDIDATE,
        self::STATE_READY,
        self::STATE_DEFAULT,
    ];

    /** Concept kinds from the Fronteira table. */
    public const KIND_SKILL = 'skill';

    public const KIND_WORKFLOW = 'workflow';

    public const KIND_AGENT = 'agent';

    public const KIND_PROVIDER = 'provider';

    public const KIND_TOOL_RUNTIME = 'tool_runtime';

    /**
     * Conflict-resolution authority order from Invariantes:
     * Output Governor -> Kernel policy -> flow owner.
     *
     * @var list<string>
     */
    private const CONFLICT_AUTHORITY_ORDER = ['output_governor', 'kernel_policy', 'flow_owner'];

    /**
     * Decide the next lifecycle state for a skill given a transition signal.
     *
     * This is the heart of the doc's Lifecycle table. It refuses illegal jumps
     * (e.g. draft -> default) and refuses to advance to `ready`/`default` when
     * the evidence/review gates are not met, returning the reason instead.
     *
     * @param  array{
     *     current_state?: string,
     *     requested_state?: string,
     *     has_short_spec?: bool,
     *     has_proposed_eval?: bool,
     *     has_permission_scope?: bool,
     *     has_comparable_traces?: bool,
     *     evidence_of_gain_vs_baseline?: bool,
     *     human_review_passed?: bool,
     *     safety_approved?: bool,
     *     regressed?: bool,
     * }  $skill
     * @return array{
     *     allowed: bool,
     *     from: string,
     *     to: string,
     *     resulting_state: string,
     *     blocking_reasons: list<string>,
     *     satisfied_gates: list<string>,
     *     is_promotion: bool,
     *     is_regression: bool,
     * }
     */
    public function decideTransition(array $skill): array
    {
        $from = $this->normalizeState($skill['current_state'] ?? self::STATE_DRAFT);
        $to = $this->normalizeState($skill['requested_state'] ?? self::STATE_CANDIDATE);

        $blocking = [];
        $satisfied = [];

        // Regression to deprecated is always permitted (skill piorou/conflitou/
        // foi substituida) — it is the safety valve and bypasses forward gates.
        if ($to === self::STATE_DEPRECATED) {
            $regressed = (bool) ($skill['regressed'] ?? true);
            if (! $regressed) {
                $blocking[] = 'deprecate requires a regression/conflict/replacement signal';
            } else {
                $satisfied[] = 'regression_signal_present';
            }

            return $this->result($from, $to, $blocking, $satisfied);
        }

        // No-op / staying put is always allowed.
        if ($from === $to) {
            return $this->result($from, $to, [], ['no_op_same_state']);
        }

        // Cannot advance out of a terminal deprecated state forward; it must be
        // re-drafted, not promoted in place.
        if ($from === self::STATE_DEPRECATED) {
            $blocking[] = 'deprecated skill cannot be promoted in place; start a new draft';

            return $this->result($from, $to, $blocking, $satisfied);
        }

        $fromRank = $this->rank($from);
        $toRank = $this->rank($to);

        // Forward progression must be exactly one step — no skipping states.
        if ($toRank > $fromRank + 1) {
            $blocking[] = sprintf(
                'illegal jump %s -> %s: lifecycle advances one step at a time',
                $from,
                $to,
            );

            return $this->result($from, $to, $blocking, $satisfied);
        }

        // Going backwards (other than deprecate) is not a valid promotion.
        if ($toRank < $fromRank) {
            $blocking[] = sprintf('cannot move backward %s -> %s without deprecating', $from, $to);

            return $this->result($from, $to, $blocking, $satisfied);
        }

        // Per-target entry gates.
        switch ($to) {
            case self::STATE_CANDIDATE:
                // draft -> candidate: needs metadata + permission scope + A/B cases.
                if (! ($skill['has_short_spec'] ?? false)) {
                    $blocking[] = 'candidate requires a short spec';
                } else {
                    $satisfied[] = 'short_spec';
                }
                if (! ($skill['has_permission_scope'] ?? false)) {
                    $blocking[] = 'candidate requires a declared permission scope';
                } else {
                    $satisfied[] = 'permission_scope';
                }
                break;

            case self::STATE_READY:
                // candidate -> ready: needs comparable traces AND evidence of gain.
                if (! ($skill['has_comparable_traces'] ?? false)) {
                    $blocking[] = 'ready requires comparable A/B traces';
                } else {
                    $satisfied[] = 'comparable_traces';
                }
                if (! ($skill['evidence_of_gain_vs_baseline'] ?? false)) {
                    $blocking[] = 'ready requires evidence of gain against baseline';
                } else {
                    $satisfied[] = 'evidence_of_gain_vs_baseline';
                }
                break;

            case self::STATE_DEFAULT:
                // ready -> default: human review + safety. The doc's hard line:
                // "Skill sem eval/evidence nao vira default".
                if (! ($skill['evidence_of_gain_vs_baseline'] ?? false)) {
                    $blocking[] = 'default forbidden without eval/evidence (skill sem evidence nao vira default)';
                } else {
                    $satisfied[] = 'evidence_carried_from_ready';
                }
                if (! ($skill['human_review_passed'] ?? false)) {
                    $blocking[] = 'default requires human review';
                } else {
                    $satisfied[] = 'human_review';
                }
                if (! ($skill['safety_approved'] ?? false)) {
                    $blocking[] = 'default requires safety approval';
                } else {
                    $satisfied[] = 'safety_approved';
                }
                break;
        }

        return $this->result($from, $to, $blocking, $satisfied);
    }

    /**
     * Classify an Atlas concept against the Fronteira boundary table.
     *
     * A "skill" is the only kind that is a versioned operational contract with an
     * eval; an "agent" is explicitly NOT an authority of its own. We score the
     * observed traits against each kind's defining rule and return the winner.
     *
     * @param  array{
     *     is_versioned?: bool,
     *     has_eval?: bool,
     *     has_output_contract?: bool,
     *     has_permission_scope?: bool,
     *     sequences_multiple_skills?: bool,
     *     is_temporary_role?: bool,
     *     is_swappable_engine?: bool,
     *     executes_permitted_actions?: bool,
     * }  $concept
     * @return array{
     *     kind: string,
     *     is_skill: bool,
     *     reason: string,
     *     authority_of_its_own: bool,
     * }
     */
    public function classifyConcept(array $concept): array
    {
        // Provider: a swappable engine that runs under a skill. Checked first
        // because it is the clearest disqualifier from "skill".
        if (($concept['is_swappable_engine'] ?? false) && ! ($concept['is_versioned'] ?? false)) {
            return [
                'kind' => self::KIND_PROVIDER,
                'is_skill' => false,
                'reason' => 'swappable engine that executes under a skill; not an authority',
                'authority_of_its_own' => false,
            ];
        }

        // Agent: a temporary role in an operation, no authority of its own.
        if (($concept['is_temporary_role'] ?? false)
            && ! ($concept['has_output_contract'] ?? false)
            && ! ($concept['is_versioned'] ?? false)) {
            return [
                'kind' => self::KIND_AGENT,
                'is_skill' => false,
                'reason' => 'temporary role in an operation; not an authority of its own',
                'authority_of_its_own' => false,
            ];
        }

        // Workflow: a sequence combining a main skill and a few auxiliaries.
        if (($concept['sequences_multiple_skills'] ?? false)) {
            return [
                'kind' => self::KIND_WORKFLOW,
                'is_skill' => false,
                'reason' => 'sequence combining a main skill and a few auxiliary skills',
                'authority_of_its_own' => false,
            ];
        }

        // Tool runtime: executes actions permitted by policy/skill/receipt and is
        // not itself a versioned contract.
        if (($concept['executes_permitted_actions'] ?? false) && ! ($concept['is_versioned'] ?? false)) {
            return [
                'kind' => self::KIND_TOOL_RUNTIME,
                'is_skill' => false,
                'reason' => 'executes actions permitted by policy/skill/receipt',
                'authority_of_its_own' => false,
            ];
        }

        // Skill: lens + policy + procedure + output contract + permission + eval,
        // versioned. This is the strongest classification and only it is a skill.
        $isSkill = ($concept['is_versioned'] ?? false)
            && ($concept['has_output_contract'] ?? false)
            && ($concept['has_eval'] ?? false);

        if ($isSkill) {
            return [
                'kind' => self::KIND_SKILL,
                'is_skill' => true,
                'reason' => 'versioned operational contract with output contract and eval',
                'authority_of_its_own' => true,
            ];
        }

        // Falls short of skill but does not match another kind cleanly: it is a
        // draft-stage skill aspirant, not yet a real skill.
        return [
            'kind' => self::KIND_SKILL,
            'is_skill' => false,
            'reason' => 'aspires to be a skill but lacks versioning, output contract or eval',
            'authority_of_its_own' => false,
        ];
    }

    /**
     * Resolve a conflict between two skills using the fixed authority order from
     * Invariantes: Output Governor -> Kernel policy -> flow owner.
     *
     * The first authority that expresses a verdict wins; lower authorities are
     * never consulted once a higher one decides.
     *
     * @param  array{
     *     output_governor?: ?string,
     *     kernel_policy?: ?string,
     *     flow_owner?: ?string,
     * }  $verdicts  Each value is the winning skill ref, or null/absent if that
     *                authority is silent.
     * @return array{
     *     resolved: bool,
     *     winner: ?string,
     *     decided_by: ?string,
     *     authority_order: list<string>,
     *     consulted: list<string>,
     * }
     */
    public function resolveConflict(array $verdicts): array
    {
        $consulted = [];

        foreach (self::CONFLICT_AUTHORITY_ORDER as $authority) {
            $consulted[] = $authority;
            $verdict = $verdicts[$authority] ?? null;

            if (is_string($verdict) && $verdict !== '') {
                return [
                    'resolved' => true,
                    'winner' => $verdict,
                    'decided_by' => $authority,
                    'authority_order' => self::CONFLICT_AUTHORITY_ORDER,
                    'consulted' => $consulted,
                ];
            }
        }

        return [
            'resolved' => false,
            'winner' => null,
            'decided_by' => null,
            'authority_order' => self::CONFLICT_AUTHORITY_ORDER,
            'consulted' => $consulted,
        ];
    }

    /**
     * Gate the adoption of an EXTERNAL skill, which the doc classifies as a
     * software dependency: it needs review, pinning (version/hash) and rollback.
     *
     * @param  array{
     *     reviewed?: bool,
     *     pinned_version?: ?string,
     *     pinned_hash?: ?string,
     *     rollback_plan?: bool,
     * }  $external
     * @return array{
     *     admissible: bool,
     *     missing_controls: list<string>,
     *     satisfied_controls: list<string>,
     * }
     */
    public function admitExternalSkill(array $external): array
    {
        $missing = [];
        $satisfied = [];

        if (! ($external['reviewed'] ?? false)) {
            $missing[] = 'review';
        } else {
            $satisfied[] = 'review';
        }

        $pinned = (is_string($external['pinned_version'] ?? null) && $external['pinned_version'] !== '')
            || (is_string($external['pinned_hash'] ?? null) && $external['pinned_hash'] !== '');
        if (! $pinned) {
            $missing[] = 'pinning';
        } else {
            $satisfied[] = 'pinning';
        }

        if (! ($external['rollback_plan'] ?? false)) {
            $missing[] = 'rollback';
        } else {
            $satisfied[] = 'rollback';
        }

        return [
            'admissible' => $missing === [],
            'missing_controls' => $missing,
            'satisfied_controls' => $satisfied,
        ];
    }

    /**
     * @param  list<string>  $blocking
     * @param  list<string>  $satisfied
     * @return array{
     *     allowed: bool,
     *     from: string,
     *     to: string,
     *     resulting_state: string,
     *     blocking_reasons: list<string>,
     *     satisfied_gates: list<string>,
     *     is_promotion: bool,
     *     is_regression: bool,
     * }
     */
    private function result(string $from, string $to, array $blocking, array $satisfied): array
    {
        $allowed = $blocking === [];
        $isRegression = $to === self::STATE_DEPRECATED;

        return [
            'allowed' => $allowed,
            'from' => $from,
            'to' => $to,
            'resulting_state' => $allowed ? $to : $from,
            'blocking_reasons' => array_values($blocking),
            'satisfied_gates' => array_values($satisfied),
            'is_promotion' => $allowed && ! $isRegression && $to !== $from,
            'is_regression' => $allowed && $isRegression,
        ];
    }

    private function rank(string $state): int
    {
        $rank = array_search($state, self::PROGRESSION, true);

        return $rank === false ? -1 : $rank;
    }

    private function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));
        $valid = [
            self::STATE_DRAFT,
            self::STATE_CANDIDATE,
            self::STATE_READY,
            self::STATE_DEFAULT,
            self::STATE_DEPRECATED,
        ];

        if (! in_array($state, $valid, true)) {
            throw new InvalidArgumentException(sprintf("unknown lifecycle state '%s'", $state));
        }

        return $state;
    }
}
