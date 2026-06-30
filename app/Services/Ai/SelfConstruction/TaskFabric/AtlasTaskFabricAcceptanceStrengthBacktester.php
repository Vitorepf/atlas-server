<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure, facts-only acceptance-criteria strength backtester. Scores a packet's acceptance_criteria
 * against the historical weak-green and give_back patterns (exit-code-only, schema-only, snapshot-only,
 * human-reviewed, implementation-blind) BEFORE the packet is enqueued, so weak acceptance never reaches a
 * muscle. Deliberately narrow: it scores acceptance TEXT, it does not run anything or add a new framework.
 *
 * Per-criterion classification looks for:
 *   - a deterministic RUNNABLE command signal (test/artisan/php /runs /executes),
 *   - a POSITIVE behavior assertion (the criterion names a concrete expected outcome),
 *   - a NEGATIVE/edge-case assertion (the criterion names a rejection/failure/edge condition),
 *   - weak-only patterns: exit-code-only, schema-only, snapshot-only, implementation-blind,
 *   - human/operator/manual review phrases, which always force a human_dependency finding.
 *
 * High-risk packets (risk_level high|irreversible) additionally require at least one negative case
 * across the whole criteria set.
 */
final class AtlasTaskFabricAcceptanceStrengthBacktester
{
    public const SCHEMA = 'atlas.self_construction.task_fabric.acceptance_strength_backtester.v1';

    public const TIER_STRONG = 'strong';

    public const TIER_MODERATE = 'moderate';

    public const TIER_WEAK = 'weak';

    private const RUNNABLE_TOKENS = ['test', 'artisan', 'php ', 'runs ', 'executes '];

    private const POSITIVE_WORDS = ['returns', 'produces', 'contains', 'includes', 'emits', 'admits', 'approves', 'accepts', 'creates', 'generates', 'preserves'];

    private const NEGATIVE_WORDS = ['rejects', 'blocks', 'fails', 'throws', 'denies', 'refuses', 'must not', 'never', 'give_back', 'exit 1', 'non-zero', 'raises'];

    private const HIGH_RISK_LEVELS = ['high', 'irreversible'];

    /**
     * @param  list<string>  $acceptanceCriteria
     * @return array<string, mixed>
     */
    public function backtest(array $acceptanceCriteria, string $riskLevel = 'low'): array
    {
        $criteria = array_values(array_filter(array_map('strval', $acceptanceCriteria), static fn (string $c): bool => trim($c) !== ''));

        $findings = [];
        $requiredImprovements = [];
        $totalScore = 0;
        $anyWeakCriterion = false;
        $anyNegativeCase = false;
        $anyHumanDependency = false;

        if ($criteria === []) {
            $findings[] = 'no_acceptance_criteria';
            $requiredImprovements[] = 'add_at_least_one_acceptance_criterion';
        }

        foreach ($criteria as $criterion) {
            $haystack = strtolower($criterion);
            $hasRunnable = $this->containsAny($haystack, self::RUNNABLE_TOKENS);
            $hasPositive = $this->containsAny($haystack, self::POSITIVE_WORDS);
            $hasNegative = $this->containsAny($haystack, self::NEGATIVE_WORDS);
            $hasBehavior = $hasPositive || $hasNegative;

            $isHumanDependency = (bool) preg_match('/\b(human|operator|manual)\b.{0,20}\b(review|approve|approval|sign[_\- ]?off|verify)\b/', $haystack);
            $isExitCodeOnly = (bool) preg_match('/\bexit(s|ed)?\s*(code\s*)?0\b/', $haystack) && ! $hasBehavior;
            $isSchemaOnly = str_contains($haystack, 'schema') && ! $hasBehavior && ! $hasRunnable;
            $isSnapshotOnly = str_contains($haystack, 'snapshot') && ! $hasBehavior;
            $isImplementationBlind = (bool) preg_match('/\b(should be|is)\s+(complete|done|implemented|working|functional|finished)\b/', $haystack) && ! $hasBehavior;

            if ($isHumanDependency) {
                $findings[] = 'human_dependency';
                $requiredImprovements[] = 'remove_human_dependency_make_it_machine_provable';
                $anyHumanDependency = true;
            }
            if ($isExitCodeOnly) {
                $findings[] = 'exit_code_only';
                $requiredImprovements[] = 'add_behavior_assertion_beyond_exit_code';
                $anyWeakCriterion = true;
            }
            if ($isSchemaOnly) {
                $findings[] = 'schema_only';
                $requiredImprovements[] = 'add_behavior_assertion_beyond_schema_shape';
                $anyWeakCriterion = true;
            }
            if ($isSnapshotOnly) {
                $findings[] = 'snapshot_only';
                $requiredImprovements[] = 'add_explicit_behavior_assertion_not_just_snapshot_match';
                $anyWeakCriterion = true;
            }
            if ($isImplementationBlind) {
                $findings[] = 'implementation_blind';
                $requiredImprovements[] = 'name_the_concrete_behavior_being_proven';
                $anyWeakCriterion = true;
            }

            $score = ($hasRunnable ? 1 : 0) + ($hasPositive ? 1 : 0) + ($hasNegative ? 1 : 0);
            $totalScore += $score;
            $anyNegativeCase = $anyNegativeCase || $hasNegative;

            if (! $hasRunnable) {
                $requiredImprovements[] = 'add_deterministic_runnable_command';
            }
        }

        $isHighRisk = in_array($riskLevel, self::HIGH_RISK_LEVELS, true);
        if ($isHighRisk && ! $anyNegativeCase) {
            $findings[] = 'high_risk_missing_negative_case';
            $requiredImprovements[] = 'add_negative_or_edge_case_for_high_risk_task';
        }

        $maxPossible = max(1, count($criteria) * 3);
        $strengthScore = (int) round(100 * $totalScore / $maxPossible);

        $tier = match (true) {
            $criteria === [] || $anyWeakCriterion || $strengthScore < 40 => self::TIER_WEAK,
            $strengthScore >= 80 => self::TIER_STRONG,
            default => self::TIER_MODERATE,
        };

        $admit = $tier !== self::TIER_WEAK
            && ! $anyHumanDependency
            && ! ($isHighRisk && ! $anyNegativeCase);

        return [
            'schema' => self::SCHEMA,
            'strength_score' => $strengthScore,
            'tier' => $tier,
            'findings' => array_values(array_unique($findings)),
            'required_improvements' => array_values(array_unique($requiredImprovements)),
            'admit' => $admit,
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
