<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure learner: analyses recent task history to compute a diversity score, detect
 * concentrated families, and recommend underrepresented high-leverage families for
 * the next wave.
 *
 * diversity_score = 1 - max_family_concentration_ratio  (0=single family, 1=uniform)
 * concentrated_families = families appearing in > 50% of the done_set
 * high_negative_signal_families = families where refused|give_back > 50% of their runs
 * missing_family_recommendations = KNOWN_FAMILIES unseen or under 10%, excluding negative-signal families
 */
final class AtlasExternalBrainDoneSetDiversityLearner
{
    public const SCHEMA = 'atlas.external_brain.done_set_diversity_learner.v1';

    public const CONCENTRATION_THRESHOLD = 0.5;

    public const UNDERREPRESENTED_THRESHOLD = 0.1;

    private const KNOWN_FAMILIES = [
        'bug-hunt',
        'discovery',
        'gate-certification',
        'gate-impl',
        'gate-wiring',
        'origination',
        'research',
        'telemetry-wiring',
        'trend-measurement',
    ];

    private const NEGATIVE_OUTCOMES = ['give_back', 'refused'];

    /**
     * @param  list<array<string,mixed>>  $doneSet  Each: task_family, outcome
     * @return array<string,mixed>
     */
    public function learn(array $doneSet): array
    {
        $total = count($doneSet);

        if ($total === 0) {
            return [
                'schema_version' => self::SCHEMA,
                'diversity_score' => 1.0,
                'concentrated_families' => [],
                'high_negative_signal_families' => [],
                'missing_family_recommendations' => self::KNOWN_FAMILIES,
            ];
        }

        $familyCounts = [];
        $negativeCounts = [];

        foreach ($doneSet as $task) {
            $family = (string) ($task['task_family'] ?? 'unknown');
            $outcome = (string) ($task['outcome'] ?? 'served');
            $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            if (in_array($outcome, self::NEGATIVE_OUTCOMES, true)) {
                $negativeCounts[$family] = ($negativeCounts[$family] ?? 0) + 1;
            }
        }

        $maxConcentration = max(array_map(static fn (int $c): float => $c / $total, $familyCounts));
        $diversityScore = round(1.0 - $maxConcentration, 3);

        $concentrated = [];
        foreach ($familyCounts as $family => $count) {
            if ($count / $total > self::CONCENTRATION_THRESHOLD) {
                $concentrated[] = $family;
            }
        }
        sort($concentrated);

        $highNegative = [];
        foreach ($familyCounts as $family => $count) {
            $neg = $negativeCounts[$family] ?? 0;
            if ($neg / $count > 0.5) {
                $highNegative[] = $family;
            }
        }
        sort($highNegative);

        $missing = [];
        foreach (self::KNOWN_FAMILIES as $family) {
            $count = $familyCounts[$family] ?? 0;
            if ($count === 0 || ($count / $total) < self::UNDERREPRESENTED_THRESHOLD) {
                if (! in_array($family, $highNegative, true)) {
                    $missing[] = $family;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'diversity_score' => $diversityScore,
            'concentrated_families' => $concentrated,
            'high_negative_signal_families' => $highNegative,
            'missing_family_recommendations' => $missing,
        ];
    }
}
