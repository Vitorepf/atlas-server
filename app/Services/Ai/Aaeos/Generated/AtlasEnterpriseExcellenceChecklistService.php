<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic runtime for the Atlas AI Research Self-Improvement Enterprise
 * Excellence Checklist.
 *
 * Turns the doc's four load-bearing decision structures into pure, testable
 * logic — a judgment engine that decides whether Atlas research and
 * self-improvement are operating at ultra-enterprise level:
 *
 *   - "Must Have": 13 binary gate items. The cycle is must-have-complete ONLY
 *     when every one of them holds. Any missing item fails the gate and is
 *     reported by name.
 *
 *   - "State Of Art Targets": 8 measurable targets, each with a documented
 *     comparator. They are NOT all the same shape: the primary-source ratio must
 *     be strictly ABOVE 0.80 for critical claims; the hallucinated-source rate
 *     must equal EXACTLY 0; P0 research-to-doc promotion must be BELOW 24h; the
 *     high-risk-before-doc/AP count must be 0 ("never"); and four targets are
 *     "trends downward / has evidence" booleans. Each target is evaluated with
 *     its own rule, so the engine genuinely enforces ">80%", "==0" and "<24h"
 *     rather than treating them as interchangeable.
 *
 *   - "Ultra-Enterprise Bar": the 8-step capability loop. The bar is reached only
 *     when Atlas can REPEATABLY do all 8 steps (notice -> verify -> map -> update
 *     docs/APs -> implement small validated blocks -> measure -> learn from
 *     failure -> avoid silent unsafe autonomy). A single non-repeatable step
 *     means the bar is not reached.
 *
 *   - "Current Posture": runtime is proposal-first. Autonomous research (a
 *     background crawler / autonomous scheduler) MAY NOT run until the required
 *     next step is satisfied — a read-only research packet/schema AND a source
 *     gate exist — and even then it must stay proposal-first, never silent
 *     unsafe autonomy.
 *
 * Pure functions only: no database, no IO, no clock. Same input -> same output.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-enterprise-excellence-checklist.md
 */
class AtlasEnterpriseExcellenceChecklistService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.enterprise_excellence_checklist.v1';

    /**
     * The 13 "Must Have" items, in documented order. The research/self-improvement
     * cycle is must-have-complete only when EVERY item holds.
     *
     * @var list<string>
     */
    public const MUST_HAVE_ITEMS = [
        'raw_source_evidence_retained',
        'source_tier_assigned',
        'claims_mapped_to_sources',
        'contradictions_recorded',
        'research_packet_created',
        'canonical_doc_updated_before_structural_code',
        'ap_or_plan_exists_for_risky_change',
        'implementation_block_small_and_reversible',
        'focused_tests_run',
        'docs_health_run_for_docs',
        'architecture_validation_run_for_structural_contracts',
        'diff_check_clean',
        'self_improvement_proposal_remains_reviewable',
    ];

    /**
     * The 8 "Ultra-Enterprise Bar" capabilities, in documented order. The bar is
     * reached only when Atlas can REPEATABLY do all 8.
     *
     * @var list<string>
     */
    public const ULTRA_ENTERPRISE_CAPABILITIES = [
        'notice_important_external_advances',
        'verify_against_primary_sources',
        'map_to_atlas_architecture',
        'update_docs_and_aps',
        'implement_small_validated_blocks',
        'measure_effect',
        'learn_from_failure',
        'avoid_silent_unsafe_autonomy',
    ];

    /**
     * The single capability whose failure is not merely "bar not reached" but an
     * active safety violation: Atlas must never exercise silent unsafe autonomy.
     */
    public const SAFETY_CRITICAL_CAPABILITY = 'avoid_silent_unsafe_autonomy';

    /**
     * "State Of Art Targets" comparators. Each target declares the rule the doc
     * states, so the engine evaluates ">80%", "==0", "<24h" and "boolean trend /
     * evidence" each on its own terms.
     *
     * comparator semantics:
     *   gt   -> value must be strictly greater than threshold (primary-source ratio > 0.80)
     *   eq   -> value must equal threshold exactly (hallucinated-source rate == 0)
     *   lt   -> value must be strictly less than threshold (P0 promotion < 24h)
     *   bool -> value must be boolean true (trend downward / has measured evidence)
     *
     * @var array<string,array{comparator:string,threshold:float}>
     */
    public const STATE_OF_ART_TARGETS = [
        'primary_source_ratio_for_critical_claims' => ['comparator' => 'gt', 'threshold' => 0.80],
        'hallucinated_source_rate' => ['comparator' => 'eq', 'threshold' => 0.0],
        'research_to_doc_promotion_hours_p0' => ['comparator' => 'lt', 'threshold' => 24.0],
        'high_risk_implementation_before_doc_or_ap_count' => ['comparator' => 'eq', 'threshold' => 0.0],
        'rework_from_weak_research_trends_downward' => ['comparator' => 'bool', 'threshold' => 1.0],
        'self_improvement_proposal_false_positive_trends_downward' => ['comparator' => 'bool', 'threshold' => 1.0],
        'retrieval_long_session_improvements_have_evidence' => ['comparator' => 'bool', 'threshold' => 1.0],
        'provider_release_absorption_passes_source_gate' => ['comparator' => 'bool', 'threshold' => 1.0],
    ];

    /**
     * "Current Posture" preconditions. The required next step is to implement a
     * read-only research packet/schema AND a source gate before any background
     * crawler or autonomous research scheduler. Both must hold before autonomy is
     * even eligible.
     *
     * @var list<string>
     */
    public const AUTONOMY_PRECONDITIONS = [
        'read_only_research_packet_schema_exists',
        'source_gate_exists',
    ];

    /**
     * Evaluate the 13-item "Must Have" gate. The cycle is complete only when every
     * item is satisfied; any missing item is reported by name. Unknown keys in the
     * input are ignored — only the documented items count.
     *
     * @param  array<string,bool>  $checklist  must-have item -> satisfied?
     * @return array{schema:string,complete:bool,satisfied:list<string>,missing:list<string>,satisfied_count:int,missing_count:int,total:int,reason:string}
     */
    public function evaluateMustHave(array $checklist): array
    {
        $satisfied = [];
        $missing = [];

        foreach (self::MUST_HAVE_ITEMS as $item) {
            if (($checklist[$item] ?? false) === true) {
                $satisfied[] = $item;
            } else {
                $missing[] = $item;
            }
        }

        $complete = $missing === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'complete' => $complete,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'satisfied_count' => count($satisfied),
            'missing_count' => count($missing),
            'total' => count(self::MUST_HAVE_ITEMS),
            'reason' => $complete
                ? 'all_13_must_have_items_satisfied'
                : 'must_have_incomplete:'.implode(',', $missing),
        ];
    }

    /**
     * Evaluate the "State Of Art Targets". Each target is checked with its own
     * documented comparator, so ">80%" is strictly above, "==0" is exactly zero,
     * "<24h" is strictly below, and the trend/evidence targets must be boolean
     * true. A target with no supplied measurement is treated as unmet (and listed
     * as missing-measurement), because absence of evidence cannot pass the bar.
     *
     * @param  array<string,int|float|bool|null>  $measurements  target -> measured value
     * @return array{schema:string,all_targets_met:bool,met:list<string>,unmet:list<string>,missing_measurement:list<string>,met_count:int,total:int,reason:string}
     */
    public function evaluateStateOfArtTargets(array $measurements): array
    {
        $met = [];
        $unmet = [];
        $missingMeasurement = [];

        foreach (self::STATE_OF_ART_TARGETS as $target => $rule) {
            if (! array_key_exists($target, $measurements) || $measurements[$target] === null) {
                $unmet[] = $target;
                $missingMeasurement[] = $target;

                continue;
            }

            if ($this->targetMet($rule['comparator'], $measurements[$target], $rule['threshold'])) {
                $met[] = $target;
            } else {
                $unmet[] = $target;
            }
        }

        $allMet = $unmet === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'all_targets_met' => $allMet,
            'met' => $met,
            'unmet' => $unmet,
            'missing_measurement' => $missingMeasurement,
            'met_count' => count($met),
            'total' => count(self::STATE_OF_ART_TARGETS),
            'reason' => $allMet
                ? 'all_8_state_of_art_targets_met'
                : 'state_of_art_targets_unmet:'.implode(',', $unmet),
        ];
    }

    /**
     * Evaluate the "Ultra-Enterprise Bar". The bar is reached only when Atlas can
     * REPEATABLY do all 8 capabilities. A capability counts only when it is marked
     * repeatable (=== true); a one-off success (false/absent) does not count.
     *
     * If the safety-critical capability ("avoid silent unsafe autonomy") is the
     * one missing, the reason flags it explicitly — that is not just an unmet bar
     * but a safety regression.
     *
     * @param  array<string,bool>  $repeatable  capability -> repeatably demonstrated?
     * @return array{schema:string,bar_reached:bool,repeatable:list<string>,not_repeatable:list<string>,repeatable_count:int,total:int,safety_breach:bool,reason:string}
     */
    public function evaluateUltraEnterpriseBar(array $repeatable): array
    {
        $repeatableList = [];
        $notRepeatable = [];

        foreach (self::ULTRA_ENTERPRISE_CAPABILITIES as $capability) {
            if (($repeatable[$capability] ?? false) === true) {
                $repeatableList[] = $capability;
            } else {
                $notRepeatable[] = $capability;
            }
        }

        $barReached = $notRepeatable === [];
        $safetyBreach = in_array(self::SAFETY_CRITICAL_CAPABILITY, $notRepeatable, true);

        if ($barReached) {
            $reason = 'ultra_enterprise_bar_reached:all_8_capabilities_repeatable';
        } elseif ($safetyBreach) {
            $reason = 'bar_not_reached_safety_breach:avoid_silent_unsafe_autonomy_not_repeatable';
        } else {
            $reason = 'ultra_enterprise_bar_not_reached:'.implode(',', $notRepeatable);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'bar_reached' => $barReached,
            'repeatable' => $repeatableList,
            'not_repeatable' => $notRepeatable,
            'repeatable_count' => count($repeatableList),
            'total' => count(self::ULTRA_ENTERPRISE_CAPABILITIES),
            'safety_breach' => $safetyBreach,
            'reason' => $reason,
        ];
    }

    /**
     * Decide whether autonomous research (a background crawler / autonomous
     * scheduler) may run, per "Current Posture". Runtime is proposal-first: the
     * required next step — a read-only research packet/schema AND a source gate —
     * must exist first. Even when both preconditions hold, autonomy is permitted
     * ONLY in proposal-first mode; a request for non-proposal (silent / fully
     * autonomous) execution is denied as unsafe autonomy.
     *
     * @param  array<string,bool>  $preconditions  precondition -> satisfied?
     * @param  bool  $proposalFirst  is the requested run proposal-first (vs silent autonomy)?
     * @return array{schema:string,may_run:bool,posture:string,met_preconditions:list<string>,missing_preconditions:list<string>,reason:string}
     */
    public function evaluateAutonomyGate(array $preconditions, bool $proposalFirst = true): array
    {
        $met = [];
        $missing = [];

        foreach (self::AUTONOMY_PRECONDITIONS as $precondition) {
            if (($preconditions[$precondition] ?? false) === true) {
                $met[] = $precondition;
            } else {
                $missing[] = $precondition;
            }
        }

        $preconditionsMet = $missing === [];

        if (! $preconditionsMet) {
            $mayRun = false;
            $posture = 'blocked_pending_required_next_step';
            $reason = 'autonomy_blocked:implement_read_only_packet_schema_and_source_gate_first:'.implode(',', $missing);
        } elseif (! $proposalFirst) {
            $mayRun = false;
            $posture = 'blocked_unsafe_autonomy';
            $reason = 'autonomy_blocked:runtime_is_proposal_first_silent_autonomy_not_allowed';
        } else {
            $mayRun = true;
            $posture = 'proposal_first';
            $reason = 'autonomy_allowed_proposal_first:read_only_packet_schema_and_source_gate_present';
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'may_run' => $mayRun,
            'posture' => $posture,
            'met_preconditions' => $met,
            'missing_preconditions' => $missing,
            'reason' => $reason,
        ];
    }

    /**
     * Full ultra-enterprise verdict: combine the Must-Have gate, the State Of Art
     * Targets, the Ultra-Enterprise Bar and the autonomy posture into one
     * judgment. Atlas is at ultra-enterprise level only when ALL three substantive
     * gates pass (must-have complete, every target met, bar reached) AND autonomy
     * is not in an unsafe state. The autonomy gate is read for posture only — its
     * being correctly blocked-pending does not by itself fail the verdict, but a
     * safety breach in the bar always does.
     *
     * @param  array<string,bool>  $mustHave        must-have item -> satisfied?
     * @param  array<string,int|float|bool|null>  $measurements  state-of-art target -> value
     * @param  array<string,bool>  $repeatable      ultra-enterprise capability -> repeatable?
     * @param  array<string,bool>  $autonomyPre     autonomy precondition -> satisfied?
     * @param  bool  $proposalFirst  requested autonomy is proposal-first?
     * @return array{schema:string,must_have:array<string,mixed>,state_of_art:array<string,mixed>,ultra_enterprise_bar:array<string,mixed>,autonomy:array<string,mixed>,at_ultra_enterprise_level:bool,verdict:string,reason:string}
     */
    public function verdict(
        array $mustHave = [],
        array $measurements = [],
        array $repeatable = [],
        array $autonomyPre = [],
        bool $proposalFirst = true
    ): array {
        $mustHaveResult = $this->evaluateMustHave($mustHave);
        $targetsResult = $this->evaluateStateOfArtTargets($measurements);
        $barResult = $this->evaluateUltraEnterpriseBar($repeatable);
        $autonomyResult = $this->evaluateAutonomyGate($autonomyPre, $proposalFirst);

        $atLevel = $mustHaveResult['complete']
            && $targetsResult['all_targets_met']
            && $barResult['bar_reached'];

        if ($barResult['safety_breach']) {
            $verdict = 'safety_breach';
            $reason = 'ultra_enterprise_blocked:silent_unsafe_autonomy_is_a_safety_breach';
        } elseif ($atLevel) {
            $verdict = 'ultra_enterprise';
            $reason = 'must_have_complete_targets_met_and_bar_reached';
        } elseif (! $mustHaveResult['complete']) {
            $verdict = 'not_ultra_enterprise';
            $reason = 'must_have_incomplete:'.implode(',', $mustHaveResult['missing']);
        } elseif (! $targetsResult['all_targets_met']) {
            $verdict = 'not_ultra_enterprise';
            $reason = 'state_of_art_targets_unmet:'.implode(',', $targetsResult['unmet']);
        } else {
            $verdict = 'not_ultra_enterprise';
            $reason = 'ultra_enterprise_bar_not_reached:'.implode(',', $barResult['not_repeatable']);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'must_have' => $mustHaveResult,
            'state_of_art' => $targetsResult,
            'ultra_enterprise_bar' => $barResult,
            'autonomy' => $autonomyResult,
            'at_ultra_enterprise_level' => $atLevel,
            'verdict' => $verdict,
            'reason' => $reason,
        ];
    }

    /**
     * Apply one State-Of-Art comparator to a single measurement.
     */
    private function targetMet(string $comparator, int|float|bool $value, float $threshold): bool
    {
        return match ($comparator) {
            'gt' => is_bool($value) ? false : (float) $value > $threshold,
            'lt' => is_bool($value) ? false : (float) $value < $threshold,
            'eq' => is_bool($value) ? false : (float) $value === $threshold,
            'bool' => $value === true,
            default => false,
        };
    }
}
