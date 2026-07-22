<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

final class AtlasLoopV2AutoRollbackDecider
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_v2.rollback.v1';

    /**
     * @var array<string,int>
     */
    private const TIER_WEIGHTS = [
        'safe' => 0,
        'moderate' => 10,
        'wide' => 25,
        'critical' => 50,
    ];

    public function __construct(
        private readonly AtlasLoopV2BlastRadiusCalculator $blastCalc,
        private readonly AtlasLoopV2AuditJournal $journal,
    ) {}

    /**
     * @param  array<string,mixed>  $postMergeSignals
     * @param  array<string,mixed>  $blastRadiusResult
     * @return array{action:'none'|'quarantine'|'revert',evidence:array<string,mixed>,reason:string,schema_version:string,severity:int}
     */
    public function decide(array $postMergeSignals, array $blastRadiusResult): array
    {
        $tier = $this->tier((string) ($blastRadiusResult['tier'] ?? 'safe'));
        $testsFailing = $this->nonNegativeInt($postMergeSignals['tests_failing'] ?? 0);
        $redMainStreak = $this->nonNegativeInt($postMergeSignals['red_main_streak'] ?? 0);
        $perfRegressionPct = $this->nonNegativeFloat($postMergeSignals['perf_regression_pct'] ?? 0.0);

        $output = $this->jsonStable($this->sorted([
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $this->action($tier, $testsFailing, $redMainStreak, $perfRegressionPct),
            'reason' => $this->reason($tier, $testsFailing, $redMainStreak, $perfRegressionPct),
            'severity' => $this->severity($tier, $testsFailing, $redMainStreak, $perfRegressionPct),
            'evidence' => [
                'blast_radius' => $blastRadiusResult,
                'blast_tier' => $tier,
                'perf_regression_pct' => $perfRegressionPct,
                'red_main_streak' => $redMainStreak,
                'tests_failing' => $testsFailing,
            ],
        ]));

        $this->journal->append('rollback_decision', $output);

        return $output;
    }

    private function action(string $tier, int $testsFailing, int $redMainStreak, float $perfRegressionPct): string
    {
        if ($tier === 'critical' && $testsFailing > 0) {
            return 'revert';
        }
        if ($redMainStreak >= 2) {
            return 'revert';
        }
        if (in_array($tier, ['wide', 'moderate'], true) && ($testsFailing > 0 || $perfRegressionPct >= 10.0)) {
            return 'quarantine';
        }

        return 'none';
    }

    private function reason(string $tier, int $testsFailing, int $redMainStreak, float $perfRegressionPct): string
    {
        if ($tier === 'critical' && $testsFailing > 0) {
            return 'critical_blast_radius_with_test_failures';
        }
        if ($redMainStreak >= 2) {
            return 'red_main_streak_threshold_reached';
        }
        if (in_array($tier, ['wide', 'moderate'], true) && $testsFailing > 0) {
            return 'blast_radius_with_test_failures';
        }
        if (in_array($tier, ['wide', 'moderate'], true) && $perfRegressionPct >= 10.0) {
            return 'blast_radius_with_perf_regression';
        }

        return 'post_merge_signals_within_rollback_thresholds';
    }

    private function severity(string $tier, int $testsFailing, int $redMainStreak, float $perfRegressionPct): int
    {
        return min(
            100,
            ($testsFailing * 15)
            + ($redMainStreak * 30)
            + (int) round($perfRegressionPct)
            + self::TIER_WEIGHTS[$tier],
        );
    }

    private function tier(string $tier): string
    {
        return array_key_exists($tier, self::TIER_WEIGHTS) ? $tier : 'safe';
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function nonNegativeFloat(mixed $value): float
    {
        return max(0.0, (float) $value);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function jsonStable(array $payload): array
    {
        return json_decode(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sorted(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortNested($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function sortNested(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortNested($value);
            }
        }

        return $payload;
    }
}
