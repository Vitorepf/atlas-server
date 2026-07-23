<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure comparator that ranks candidate tasks by expected downstream capability gain
 * over shallow queue depth, test-count, or wrapper-count proxies.
 *
 * High-leverage categories (ranked first):
 *   - closed_loop_learning: tasks that create feedback loops improving future outcomes
 *   - autonomy_repair: tasks that fix autonomy-blocking issues
 *   - collision_prevention: tasks that prevent future queue collisions
 *
 * Low-leverage categories (ranked last):
 *   - cosmetic_wrapper: tasks that add wrappers without capability gain
 *   - raw_task_count_expansion: tasks that increase task count without structural value
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainStructuralLeverageComparator
{
    public const SCHEMA = 'atlas.external_brain.structural_leverage_comparator.v1';

    private const LEVERAGE_RANK = [
        'closed_loop_learning' => 0,
        'autonomy_repair' => 1,
        'collision_prevention' => 2,
        'capability_expansion' => 3,
        'test_coverage' => 4,
        'refactor' => 5,
        'cosmetic_wrapper' => 6,
        'raw_task_count_expansion' => 7,
    ];

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function rank(array $candidates): array
    {
        $enriched = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = (string) ($candidate['task_id'] ?? $candidate['candidate_id'] ?? '');
            $category = (string) ($candidate['leverage_category'] ?? 'raw_task_count_expansion');
            $downstreamGain = (float) ($candidate['expected_downstream_gain'] ?? 0.0);
            $queueDepth = (int) ($candidate['queue_depth'] ?? 0);
            $testCount = (int) ($candidate['test_count'] ?? 0);
            $wrapperCount = (int) ($candidate['wrapper_count'] ?? 0);

            $rank = self::LEVERAGE_RANK[$category] ?? 99;
            $leverageScore = $downstreamGain - ($queueDepth * 0.1) - ($testCount * 0.05) - ($wrapperCount * 0.2);

            $enriched[] = [
                'task_id' => $id,
                'leverage_category' => $category,
                'leverage_rank' => $rank,
                'expected_downstream_gain' => $downstreamGain,
                'leverage_score' => round($leverageScore, 3),
                'queue_depth' => $queueDepth,
                'test_count' => $testCount,
                'wrapper_count' => $wrapperCount,
            ];
        }

        // Sort by leverage_rank ASC (high-leverage first), then leverage_score DESC.
        usort($enriched, static function (array $a, array $b): int {
            return $a['leverage_rank'] <=> $b['leverage_rank']
                ?: $b['leverage_score'] <=> $a['leverage_score']
                ?: strcmp($a['task_id'], $b['task_id']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'ranked' => $enriched,
            'total_candidates' => count($enriched),
            'top_candidate' => $enriched[0] ?? null,
        ];
    }
}
