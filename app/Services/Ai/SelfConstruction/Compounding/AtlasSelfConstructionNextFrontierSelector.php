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
 *   1. blocker_removal_high_leverage  — unresolved blockers paired with a high-capability-coverage delta
 *   2. coverage_completion             — missing organ coverage rows
 *   3. lesson_consolidation            — give_back classes with repeat_count >= 2
 *   4. cosmetic_expansion              — when nothing else, only if other signals exist
 *
 * NEVER creates executable task packets — selector only emits proposed FACTS.
 */
final class AtlasSelfConstructionNextFrontierSelector
{
    public const SCHEMA = 'atlas.self_construction.next_frontier_selector.v1';

    public const KIND_BLOCKER_REMOVAL = 'blocker_removal_high_leverage';

    public const KIND_COVERAGE_COMPLETION = 'coverage_completion';

    public const KIND_LESSON_CONSOLIDATION = 'lesson_consolidation';

    public const KIND_COSMETIC_EXPANSION = 'cosmetic_expansion';

    /**
     * @param  array<string,mixed>  $leverageDelta     output of LeverageDeltaReporter::report
     * @param  list<array<string,mixed>>  $unresolvedBlockers {organ, blocker_id}
     * @param  list<string>  $missingOrganCoverage
     * @param  list<array<string,mixed>>  $giveBackLessons {class, repeat_count}
     * @return array<string,mixed>
     */
    public function select(array $leverageDelta, array $unresolvedBlockers, array $missingOrganCoverage, array $giveBackLessons): array
    {
        $frontier = [];
        $deltas = (array) ($leverageDelta['deltas'] ?? []);
        $capabilityDelta = (int) ($deltas['capability_coverage'] ?? 0);

        foreach ($unresolvedBlockers as $b) {
            if (! is_array($b)) {
                continue;
            }
            $organ = (string) ($b['organ'] ?? 'unknown_organ');
            $blockerId = (string) ($b['blocker_id'] ?? 'unknown_blocker');
            $highLeverage = $capabilityDelta >= 1;
            $frontier[] = [
                'kind' => self::KIND_BLOCKER_REMOVAL,
                'rationale' => $highLeverage
                    ? 'unresolved blocker '.$blockerId.' couples with positive capability delta — high-leverage removal'
                    : 'unresolved blocker '.$blockerId.' even without capability lift — remove to unblock',
                'owner_organ' => $organ,
                'required_gates' => ['verification_court', 'merge_governor'],
                'expected_evidence' => ['blocker_removed_test_green', 'no_regression_test_green'],
                'priority_class' => 1,
            ];
        }
        foreach ($missingOrganCoverage as $organ) {
            $organ = (string) $organ;
            $frontier[] = [
                'kind' => self::KIND_COVERAGE_COMPLETION,
                'rationale' => 'canonical organ '.$organ.' has no coverage row',
                'owner_organ' => $organ,
                'required_gates' => ['organ_contract_gate'],
                'expected_evidence' => ['organ_implementation_surface_present', 'organ_test_evidence_requirement_present'],
                'priority_class' => 2,
            ];
        }
        foreach ($giveBackLessons as $g) {
            if (! is_array($g) || (int) ($g['repeat_count'] ?? 0) < 2) {
                continue;
            }
            $class = (string) ($g['class'] ?? '');
            if ($class === '') {
                continue;
            }
            $frontier[] = [
                'kind' => self::KIND_LESSON_CONSOLIDATION,
                'rationale' => 'lesson class '.$class.' repeated '.((int) $g['repeat_count']).' times — consolidate into packet template',
                'owner_organ' => 'learning_transfer',
                'required_gates' => ['lesson_candidate_gate'],
                'expected_evidence' => ['packet_template_update_lesson_tag'],
                'priority_class' => 3,
            ];
        }

        if ($frontier === []) {
            // No supported facts ⇒ empty frontier (selector is silent, not noisy).
            return $this->envelope([]);
        }

        // Deterministic ordering: priority_class ASC then by (rationale ASC).
        usort($frontier, static fn (array $a, array $b): int => $a['priority_class'] <=> $b['priority_class'] ?: strcmp($a['rationale'], $b['rationale']));

        return $this->envelope($frontier);
    }

    /**
     * @param  list<array<string,mixed>>  $frontier
     * @return array<string,mixed>
     */
    private function envelope(array $frontier): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'frontier' => $frontier,
        ];
    }
}
