<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

/**
 * Pure FACTS-only selector. Proposes the next frontier the Self-Construction OS should pursue, from:
 *   - leverage delta facts (output of AtlasSelfConstructionLeverageDeltaReporter)
 *   - unresolved blockers
 *   - missing organ coverage
 *   - repeated give-back lessons (class + repeat_count)
 *
 * Output: {schema_version, frontier:list<{kind, rationale, owner_organ, required_gates, expected_evidence,
 *           priority_class}>}
 *
 * Priority order (lex):
 *   0. simplification_opportunity (high_value)  — cheap, low-risk simplification wins — simplification-first
 *   1. blocker_removal_high_leverage  — unresolved blockers paired with a high-capability-coverage delta
 *   2. coverage_completion             — missing organ coverage rows
 *   3. lesson_consolidation            — give_back classes with repeat_count >= 2
 *   4. cosmetic_expansion / simplification_opportunity (low value) — when nothing else, only if other signals exist
 *
 * NEVER creates executable task packets — selector only emits proposed FACTS.
 *
 * The envelope additionally reports the top pick (recommended_frontier) plus transparent, falsifiable
 * COUNTS (compound_unlock_score = total frontier rows, blocker_pressure, coverage_gap,
 * simplification_opportunity) and rationale — descriptive/auditable fields, never the mechanism driving
 * selection itself, which stays the existing lexicographic priority-tier ordering below, not a single
 * opaque composite score.
 */
final class AtlasSelfConstructionNextFrontierSelector
{
    public const SCHEMA = 'atlas.self_construction.next_frontier_selector.v1';

    public const KIND_BLOCKER_REMOVAL = 'blocker_removal_high_leverage';

    public const KIND_COVERAGE_COMPLETION = 'coverage_completion';

    public const KIND_LESSON_CONSOLIDATION = 'lesson_consolidation';

    public const KIND_COSMETIC_EXPANSION = 'cosmetic_expansion';

    public const KIND_SIMPLIFICATION = 'simplification_opportunity';

    /**
     * @param  array<string,mixed>  $leverageDelta     output of LeverageDeltaReporter::report
     * @param  list<array<string,mixed>>  $unresolvedBlockers {organ, blocker_id}
     * @param  list<string>  $missingOrganCoverage
     * @param  list<array<string,mixed>>  $giveBackLessons {class, repeat_count}
     * @param  list<array<string,mixed>>  $simplificationOpportunities {organ, opportunity_id, high_value?}
     * @param  string  $velocityRecommendation  '' | 'scale_up' | 'reduce_churn_before_scaling'
     * @return array<string,mixed>
     */
    public function select(array $leverageDelta, array $unresolvedBlockers, array $missingOrganCoverage, array $giveBackLessons, array $simplificationOpportunities = [], string $velocityRecommendation = ''): array
    {
        $frontier = [];
        $deltas = (array) ($leverageDelta['deltas'] ?? []);
        $capabilityDelta = (int) ($deltas['capability_coverage'] ?? 0);

        // When the velocity tracker recommends reducing churn before scaling,
        // lesson_consolidation (churn reduction) outranks blocker_removal and
        // coverage_completion (scaling frontiers).
        $reduceChurnFirst = $velocityRecommendation === 'reduce_churn_before_scaling';
        $lessonPriority = $reduceChurnFirst ? 1 : 3;
        $blockerPriority = $reduceChurnFirst ? 2 : 1;
        $coveragePriority = $reduceChurnFirst ? 3 : 2;

        foreach ($unresolvedBlockers as $b) {
            if (! is_array($b)) {
                continue;
            }
            $organ = (string) ($b['organ'] ?? 'unknown_organ');
            $blockerId = (string) ($b['blocker_id'] ?? 'unknown_blocker');
            $highLeverage = $capabilityDelta >= 1;
            $row = [
                'kind' => self::KIND_BLOCKER_REMOVAL,
                'rationale' => $highLeverage
                    ? 'unresolved blocker '.$blockerId.' couples with positive capability delta — high-leverage removal'
                    : 'unresolved blocker '.$blockerId.' even without capability lift — remove to unblock',
                'owner_organ' => $organ,
                'required_gates' => ['verification_court', 'merge_governor'],
                'required_evidence' => ['blocker_removed_test_green', 'no_regression_test_green'],
                'next_packet_lane' => 'self_construction_blocker_removal',
                'priority_class' => $blockerPriority,
            ];
            $row['chain_frontier_proof'] = $this->chainFrontierProof($row, ['blocker_id' => $blockerId]);
            $frontier[] = $row;
        }
        foreach ($missingOrganCoverage as $organ) {
            $organ = (string) $organ;
            $row = [
                'kind' => self::KIND_COVERAGE_COMPLETION,
                'rationale' => 'canonical organ '.$organ.' has no coverage row',
                'owner_organ' => $organ,
                'required_gates' => ['organ_contract_gate'],
                'required_evidence' => ['organ_implementation_surface_present', 'organ_test_evidence_requirement_present'],
                'next_packet_lane' => 'self_construction_coverage',
                'priority_class' => $coveragePriority,
            ];
            $row['chain_frontier_proof'] = $this->chainFrontierProof($row);
            $frontier[] = $row;
        }
        foreach ($giveBackLessons as $g) {
            if (! is_array($g) || (int) ($g['repeat_count'] ?? 0) < 2) {
                continue;
            }
            $class = (string) ($g['class'] ?? '');
            if ($class === '') {
                continue;
            }
            $row = [
                'kind' => self::KIND_LESSON_CONSOLIDATION,
                'rationale' => 'lesson class '.$class.' repeated '.((int) $g['repeat_count']).' times — consolidate into packet template',
                'owner_organ' => 'learning_transfer',
                'required_gates' => ['lesson_candidate_gate'],
                'required_evidence' => ['packet_template_update_lesson_tag'],
                'next_packet_lane' => 'self_construction_lesson_consolidation',
                'priority_class' => $lessonPriority,
            ];
            $row['chain_frontier_proof'] = $this->chainFrontierProof($row, ['lesson_class' => $class, 'repeat_count' => (int) $g['repeat_count']]);
            $frontier[] = $row;
        }
        foreach ($simplificationOpportunities as $s) {
            if (! is_array($s)) {
                continue;
            }
            $organ = (string) ($s['organ'] ?? 'unknown_organ');
            $opportunityId = (string) ($s['opportunity_id'] ?? 'unknown_opportunity');
            $highValue = (bool) ($s['high_value'] ?? false);
            $row = [
                'kind' => self::KIND_SIMPLIFICATION,
                'rationale' => $highValue
                    ? 'high-value simplification opportunity '.$opportunityId.' in '.$organ.' — cheap, low-risk win, prioritize first'
                    : 'simplification opportunity '.$opportunityId.' in '.$organ,
                'owner_organ' => $organ,
                'required_gates' => ['simplification_debt_gate'],
                'required_evidence' => ['simplification_applied_test_green', 'no_regression_test_green'],
                'next_packet_lane' => 'self_construction_simplification',
                'priority_class' => $highValue ? 0 : 4,
            ];
            $row['chain_frontier_proof'] = $this->chainFrontierProof($row, ['opportunity_id' => $opportunityId]);
            $frontier[] = $row;
        }

        if ($frontier === []) {
            // No supported facts ⇒ empty frontier (selector is silent, not noisy).
            return $this->envelope([], $unresolvedBlockers, $missingOrganCoverage, $simplificationOpportunities);
        }

        // Deterministic ordering: priority_class ASC then by (rationale ASC).
        usort($frontier, static fn (array $a, array $b): int => $a['priority_class'] <=> $b['priority_class'] ?: strcmp($a['rationale'], $b['rationale']));

        return $this->envelope($frontier, $unresolvedBlockers, $missingOrganCoverage, $simplificationOpportunities);
    }

    /**
     * @param  list<array<string,mixed>>  $frontier
     * @param  list<array<string,mixed>>  $unresolvedBlockers
     * @param  list<string>  $missingOrganCoverage
     * @param  list<array<string,mixed>>  $simplificationOpportunities
     * @return array<string,mixed>
     */
    private function envelope(array $frontier, array $unresolvedBlockers = [], array $missingOrganCoverage = [], array $simplificationOpportunities = []): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'frontier' => $frontier,
            'recommended_frontier' => $frontier[0] ?? null,
            'compound_unlock_score' => count($frontier),
            'blocker_pressure' => count($unresolvedBlockers),
            'coverage_gap' => count($missingOrganCoverage),
            'simplification_opportunity' => count($simplificationOpportunities),
            'rationale' => $frontier !== [] ? (string) $frontier[0]['rationale'] : 'no_frontier_signals_present',
        ];
    }

    /**
     * Build a deterministic chain_frontier_proof for a frontier row.
     * Pure — reads $row fields and $ctx hints, no I/O.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $ctx  extra context (blocker_id, lesson_class, repeat_count)
     * @return array{upstream_signal:string, downstream_unlock:string, why_not_cosmetic:string, expected_compounding_effect:string, next_task_family:string}
     */
    private function chainFrontierProof(array $row, array $ctx = []): array
    {
        $kind  = (string) ($row['kind'] ?? '');
        $organ = (string) ($row['owner_organ'] ?? 'unknown_organ');
        $lane  = (string) ($row['next_packet_lane'] ?? '');

        return match ($kind) {
            self::KIND_BLOCKER_REMOVAL => [
                'upstream_signal'           => 'unresolved_blocker:'.(string) ($ctx['blocker_id'] ?? 'unknown').' in organ:'.$organ,
                'downstream_unlock'         => 'removes constraint on '.$organ.' enabling downstream capability delivery',
                'why_not_cosmetic'          => 'blocker halts real capability progress; removal is structural, not surface-level polish',
                'expected_compounding_effect' => 'each removed blocker compounds: organ unblocked → more tasks claimable → more capability delivered per cycle',
                'next_task_family'          => $lane !== '' ? $lane : 'self_construction_blocker_removal',
            ],
            self::KIND_COVERAGE_COMPLETION => [
                'upstream_signal'           => 'missing_coverage_row:organ:'.$organ,
                'downstream_unlock'         => 'organ '.$organ.' becomes visible to task fabric and can receive targeted packets',
                'why_not_cosmetic'          => 'without a coverage row the organ is invisible to the loop; adding it enables measurable, testable delivery',
                'expected_compounding_effect' => 'coverage → verifiable contract → loop can originate, certify, and merge improvements to '.$organ.' autonomously',
                'next_task_family'          => $lane !== '' ? $lane : 'self_construction_coverage',
            ],
            self::KIND_LESSON_CONSOLIDATION => [
                'upstream_signal'           => 'repeated_give_back:class:'.(string) ($ctx['lesson_class'] ?? 'unknown').':count:'.(string) ($ctx['repeat_count'] ?? '?'),
                'downstream_unlock'         => 'future packets of this class succeed on first attempt, eliminating give_back overhead',
                'why_not_cosmetic'          => 'repeated give_backs indicate a structural gap in packet authoring; consolidating the lesson closes the root cause',
                'expected_compounding_effect' => 'each consolidated lesson multiplies across all future packets in its class, reducing wasted loop cycles',
                'next_task_family'          => $lane !== '' ? $lane : 'self_construction_lesson_consolidation',
            ],
            self::KIND_SIMPLIFICATION => [
                'upstream_signal'           => 'simplification_opportunity:'.(string) ($ctx['opportunity_id'] ?? 'unknown').' in organ:'.$organ,
                'downstream_unlock'         => 'reduces surface area in '.$organ.', lowering future change cost and blast radius',
                'why_not_cosmetic'          => 'a genuine simplification opportunity reduces real maintenance burden, distinct from cosmetic polish with no capability delta',
                'expected_compounding_effect' => 'each simplification reduces the cost of every future change to '.$organ.', compounding across all subsequent packets',
                'next_task_family'          => $lane !== '' ? $lane : 'self_construction_simplification',
            ],
            default => [
                'upstream_signal'           => 'kind:'.$kind,
                'downstream_unlock'         => 'unknown',
                'why_not_cosmetic'          => 'unknown',
                'expected_compounding_effect' => 'unknown',
                'next_task_family'          => $lane !== '' ? $lane : 'self_construction_unknown',
            ],
        };
    }
}
