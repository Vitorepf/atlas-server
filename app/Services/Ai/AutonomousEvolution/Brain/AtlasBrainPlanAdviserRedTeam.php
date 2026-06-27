<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PLAN-ADVISER RED TEAM — adversarial second opinion on the plan adviser's recommended path.
 * If recent cascade outcomes show the recommended path has refused/rejected most attempts (low
 * yield), this organ returns a veto with a rotation suggestion. Pure decision over already-built
 * inputs — no IO, no state. Pétreo: a réu would soften this to never veto its own path.
 */
final class AtlasBrainPlanAdviserRedTeam
{
    public const SCHEMA = 'atlas.brain.plan_adviser_red_team.v1';

    private const LOW_YIELD_RATIO = 0.20;

    private const MIN_SAMPLES = 5;

    /**
     * @param  array<string, array{accepted:int, refused:int, total:int}>  $pathRollup  shape produced by AtlasBrainCascadeRuleOutcomeAnalyzer
     * @param  list<string>  $portfolioPaths
     * @return array{schema:string, verdict:string, recommended_path:string, alternative_path:?string, reason:string}
     */
    public function review(string $recommendedPath, array $pathRollup, array $portfolioPaths): array
    {
        $stats = $pathRollup[$recommendedPath] ?? null;
        if ($stats === null || $stats['total'] < self::MIN_SAMPLES) {
            return $this->approve($recommendedPath, 'insufficient_history');
        }

        $yield = $stats['total'] > 0 ? $stats['accepted'] / $stats['total'] : 0.0;
        if ($yield >= self::LOW_YIELD_RATIO) {
            return $this->approve($recommendedPath, 'yield_ok');
        }

        $alternative = $this->pickLeastSampledAlternative($recommendedPath, $pathRollup, $portfolioPaths);

        return [
            'schema' => self::SCHEMA,
            'verdict' => 'veto',
            'recommended_path' => $recommendedPath,
            'alternative_path' => $alternative,
            'reason' => sprintf('path yield %.2f over %d samples is below %.2f threshold', $yield, $stats['total'], self::LOW_YIELD_RATIO),
        ];
    }

    /**
     * @return array{schema:string, verdict:string, recommended_path:string, alternative_path:null, reason:string}
     */
    private function approve(string $path, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => 'approve',
            'recommended_path' => $path,
            'alternative_path' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, array{accepted:int, refused:int, total:int}>  $pathRollup
     * @param  list<string>  $portfolioPaths
     */
    private function pickLeastSampledAlternative(string $excluded, array $pathRollup, array $portfolioPaths): ?string
    {
        $best = null;
        $bestTotal = PHP_INT_MAX;
        foreach ($portfolioPaths as $path) {
            if ($path === $excluded) {
                continue;
            }
            $total = $pathRollup[$path]['total'] ?? 0;
            if ($total < $bestTotal) {
                $bestTotal = $total;
                $best = $path;
            }
        }

        return $best;
    }
}
