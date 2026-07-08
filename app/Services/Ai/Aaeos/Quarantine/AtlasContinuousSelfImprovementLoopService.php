<?php

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Deterministic runtime for the Atlas AI Continuous Self-Improvement Loop.
 *
 * Turns the documented governance contract into pure, testable decision logic:
 *   - the 8 mandatory Proposal Requirements (a proposal that omits any one of
 *     them is not eligible to advance);
 *   - the 5-level promotion ladder lead -> proposal -> approved_plan ->
 *     validated_block -> promoted_law, which may NEVER skip a level and where
 *     every transition demands its own gate;
 *   - the Autonomy Principle: autonomy increases only AFTER repeated successful
 *     evidence cycles — it earns power, it never assumes it;
 *   - the documented loop flow (collect -> detect -> classify -> compare ->
 *     propose -> review -> implement -> validate -> promote/rollback), resolved
 *     proposal-first until promotion gates prove safety.
 *
 * Pure functions only: no database, no IO, no side effects. Every method returns
 * a strict typed array shape.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
 */
class AtlasContinuousSelfImprovementLoopService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.continuous_self_improvement_loop.v1';

    /**
     * The 8 fields every proposal MUST include (doc: "Proposal Requirements").
     *
     * @var list<string>
     */
    public const REQUIRED_PROPOSAL_FIELDS = [
        'source_evidence',
        'affected_docs_code',
        'risk',
        'expected_gain',
        'validation',
        'rollback',
        'autonomy_level',
        'reason_not_auto_applied',
    ];

    /**
     * The 5-level promotion ladder, in strict ascending order (doc: "Promotion
     * Levels"). Index position == ladder rank.
     *
     * @var list<string>
     */
    public const PROMOTION_LADDER = [
        'lead',
        'proposal',
        'approved_plan',
        'validated_block',
        'promoted_law',
    ];

    /**
     * The gate that each upward transition requires. Keyed by the TARGET level.
     * A transition is permitted only when its gate is satisfied; without it the
     * proposal stays where it is.
     *
     * @var array<string,string>
     */
    public const TRANSITION_GATES = [
        'proposal' => 'evidence_backed',
        'approved_plan' => 'review_passed',
        'validated_block' => 'implemented_and_tested',
        'promoted_law' => 'canonical_docs_and_runtime_gates_green',
    ];

    /**
     * The 9 ordered stages of the documented loop.
     *
     * @var list<string>
     */
    public const LOOP_STAGES = [
        'collect_evidence',
        'detect_pattern',
        'classify_risk',
        'compare_with_docs_aps',
        'generate_proposal',
        'review',
        'implement_small_block',
        'validate',
        'promote_or_rollback',
    ];

    /**
     * Autonomy is earned, never assumed. A proposal starts with NO autonomy and
     * may only step up one band at a time, and only once it has accumulated the
     * threshold number of consecutive successful evidence cycles. Keyed by
     * autonomy band -> minimum consecutive successful cycles required.
     *
     * @var array<string,int>
     */
    public const AUTONOMY_THRESHOLDS = [
        'none' => 0,
        'suggest_only' => 1,
        'assisted_apply' => 3,
        'auto_apply_low_risk' => 6,
        'auto_apply_with_audit' => 10,
    ];

    /**
     * Validate a proposal against the 8 mandatory Proposal Requirements.
     *
     * A field counts as present only when it is supplied and non-empty (a blank
     * string / empty array does not satisfy a requirement). A proposal missing
     * any required field is NOT eligible to advance — improvement is measured by
     * evidence, not by volume of changes.
     *
     * @param  array<string,mixed>  $proposal
     * @return array{schema:string,eligible:bool,missing_fields:list<string>,present_fields:list<string>,missing_count:int,satisfied_count:int,decision:string,reason:string}
     */
    public function validateProposal(array $proposal): array
    {
        $missing = [];
        $present = [];

        foreach (self::REQUIRED_PROPOSAL_FIELDS as $field) {
            if ($this->fieldPresent($proposal[$field] ?? null)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $eligible = $missing === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'eligible' => $eligible,
            'missing_fields' => $missing,
            'present_fields' => $present,
            'missing_count' => count($missing),
            'satisfied_count' => count($present),
            'decision' => $eligible ? 'eligible_to_advance' : 'blocked_incomplete_proposal',
            'reason' => $eligible
                ? 'all_8_proposal_requirements_present'
                : 'missing_required_fields:'.implode(',', $missing),
        ];
    }

    /**
     * Resolve the next promotion level for a proposal given the gates it has
     * cleared. The ladder is strictly ordered and may NEVER be skipped: the
     * result is at most one rank above the current level, and only when the gate
     * for that next rank is satisfied. Already at the top (`promoted_law`) stays
     * put.
     *
     * @param  list<string>  $satisfiedGates  gate identifiers proven for this proposal
     * @return array{schema:string,current_level:string,current_rank:int,next_level:string,next_rank:int,advanced:bool,required_gate:?string,gate_satisfied:bool,reason:string}
     */
    public function nextPromotionLevel(string $currentLevel, array $satisfiedGates = []): array
    {
        $rank = array_search($currentLevel, self::PROMOTION_LADDER, true);
        if ($rank === false) {
            throw new InvalidArgumentException("Unknown promotion level: {$currentLevel}");
        }

        $topRank = count(self::PROMOTION_LADDER) - 1;

        if ($rank >= $topRank) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'current_level' => $currentLevel,
                'current_rank' => $rank,
                'next_level' => $currentLevel,
                'next_rank' => $rank,
                'advanced' => false,
                'required_gate' => null,
                'gate_satisfied' => true,
                'reason' => 'already_at_top_of_ladder',
            ];
        }

        $candidate = self::PROMOTION_LADDER[$rank + 1];
        $requiredGate = self::TRANSITION_GATES[$candidate] ?? null;
        $gateSatisfied = $requiredGate !== null && in_array($requiredGate, $satisfiedGates, true);

        $advanced = $gateSatisfied;
        $nextLevel = $advanced ? $candidate : $currentLevel;
        $nextRank = $advanced ? $rank + 1 : $rank;

        return [
            'schema' => self::SCHEMA_VERSION,
            'current_level' => $currentLevel,
            'current_rank' => $rank,
            'next_level' => $nextLevel,
            'next_rank' => $nextRank,
            'advanced' => $advanced,
            'required_gate' => $requiredGate,
            'gate_satisfied' => $gateSatisfied,
            'reason' => $advanced
                ? 'advanced_one_level:gate_satisfied:'.(string) $requiredGate
                : 'held_at_level:missing_gate:'.(string) $requiredGate,
        ];
    }

    /**
     * Resolve the autonomy band a proposal has EARNED from its track record.
     * Autonomy increases only after repeated successful evidence cycles: a
     * negative input is clamped to zero, and the band is the highest one whose
     * threshold has been met. The result never exceeds what the cycles prove.
     *
     * @return array{schema:string,consecutive_successful_cycles:int,earned_autonomy:string,cycles_to_next_band:?int,next_band:?string,reason:string}
     */
    public function earnedAutonomyLevel(int $consecutiveSuccessfulCycles): array
    {
        $cycles = max(0, $consecutiveSuccessfulCycles);

        $earned = 'none';
        foreach (self::AUTONOMY_THRESHOLDS as $band => $threshold) {
            if ($cycles >= $threshold) {
                $earned = $band;
            }
        }

        $nextBand = null;
        $cyclesToNext = null;
        foreach (self::AUTONOMY_THRESHOLDS as $band => $threshold) {
            if ($threshold > $cycles) {
                $nextBand = $band;
                $cyclesToNext = $threshold - $cycles;
                break;
            }
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'consecutive_successful_cycles' => $cycles,
            'earned_autonomy' => $earned,
            'cycles_to_next_band' => $cyclesToNext,
            'next_band' => $nextBand,
            'reason' => $earned === 'none'
                ? 'no_autonomy_earned:autonomy_is_earned_not_assumed'
                : 'earned_through_'.$cycles.'_consecutive_successful_cycles',
        ];
    }

    /**
     * Run the documented loop as a single deterministic decision. Walks the 9
     * stages in order, but resolves proposal-first: the loop never promotes a
     * proposal that fails the 8 requirements or whose validation did not pass,
     * and a failed validation routes to rollback rather than promotion.
     *
     * @param  array<string,mixed>  $proposal           the candidate proposal
     * @param  list<string>  $satisfiedGates            promotion gates cleared so far
     * @param  bool  $validationPassed                  did the post-implementation validation pass
     * @param  int  $consecutiveSuccessfulCycles        track record feeding autonomy
     * @return array{schema:string,stages:list<string>,proposal_eligible:bool,missing_fields:list<string>,promotion:array<string,mixed>,earned_autonomy:string,validation_passed:bool,resolution:string,human_review_required:bool,reason:string}
     */
    public function runLoop(
        array $proposal,
        array $satisfiedGates = [],
        bool $validationPassed = false,
        int $consecutiveSuccessfulCycles = 0
    ): array {
        $validation = $this->validateProposal($proposal);
        $autonomy = $this->earnedAutonomyLevel($consecutiveSuccessfulCycles);
        $promotion = $this->nextPromotionLevel(
            $this->stringLevel($proposal['promotion_level'] ?? 'lead'),
            $satisfiedGates
        );

        // Proposal-first: an incomplete proposal cannot advance at all.
        if (! $validation['eligible']) {
            $resolution = 'rollback';
            $reason = 'incomplete_proposal_cannot_advance';
        } elseif (! $validationPassed) {
            // Doc flow: Validate -> Promote OR rollback. Failed validation rolls back.
            $resolution = 'rollback';
            $reason = 'validation_failed_route_to_rollback';
        } elseif ($promotion['advanced']) {
            $resolution = 'promote';
            $reason = 'validated_and_gate_satisfied:promote_to_'.$promotion['next_level'];
        } else {
            $resolution = 'hold';
            $reason = 'validated_but_promotion_gate_not_met';
        }

        // Anything short of fully-earned high autonomy keeps a human in the loop
        // for promotion. Autonomy must be earned across cycles before the loop
        // would promote without review.
        $humanReviewRequired = $resolution === 'promote'
            && $autonomy['earned_autonomy'] !== 'auto_apply_low_risk'
            && $autonomy['earned_autonomy'] !== 'auto_apply_with_audit';

        return [
            'schema' => self::SCHEMA_VERSION,
            'stages' => self::LOOP_STAGES,
            'proposal_eligible' => $validation['eligible'],
            'missing_fields' => $validation['missing_fields'],
            'promotion' => $promotion,
            'earned_autonomy' => $autonomy['earned_autonomy'],
            'validation_passed' => $validationPassed,
            'resolution' => $resolution,
            'human_review_required' => $humanReviewRequired,
            'reason' => $reason,
        ];
    }

    private function fieldPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function stringLevel(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : 'lead';
    }
}
