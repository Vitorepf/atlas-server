<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Closes the learning loop between the task muscle and the brain: adjusts future pattern-family
 * priority from real delivery outcomes and emits bounded, provider-safe next-wave search hints.
 *
 * INPUT outcomes:
 *   list<{ task_packet_id:string, pattern_family:string, outcome:string, impact?:string,
 *           give_back_count?:int }>
 *
 * OUTCOME VALUES:
 *   delivered — task completed and committed; positive signal.
 *   give_back — worker returned the task; negative signal weighted by give_back_count.
 *   proxy     — task shipped but was proxy/filler (no real capability gain); stronger negative.
 *
 * IMPACT (for delivered outcomes): high | medium | low — scales the positive delta.
 *
 * OUTPUT:
 *   { schema, priority_adjustments:list<Adjustment>, next_wave_hints:list<Hint>,
 *     promoted:list<string>, demoted:list<string> }
 *
 * Adjustment: { pattern_family, delta:float[-1.0..+1.0], reason:string }
 * Hint:       { source_category:string, focus:string, priority:float[0..1] }
 *
 * INVARIANTS:
 *   - No hidden model prompts, no external calls, no unverifiable claims. Pure output.
 *   - Delta is always clamped to [-1.0, +1.0].
 *   - promoted/demoted lists are disjoint.
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainOutcomeLearner
{
    public const SCHEMA = 'atlas.external_brain.outcome_learner.v1';

    public const OUTCOME_DELIVERED = 'delivered';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_PROXY = 'proxy';

    public const IMPACT_HIGH   = 'high';

    public const IMPACT_MEDIUM = 'medium';

    public const IMPACT_LOW    = 'low';

    /** Base deltas before per-outcome scaling. */
    private const DELTA_DELIVERED_HIGH   = +0.30;

    private const DELTA_DELIVERED_MEDIUM = +0.15;

    private const DELTA_DELIVERED_LOW    = +0.05;

    private const DELTA_GIVE_BACK_BASE   = -0.20;

    private const DELTA_PROXY            = -0.40;

    /** Minimum absolute delta before a pattern is considered promoted/demoted. */
    private const PROMOTE_THRESHOLD = 0.01;

    /**
     * @param  list<array{task_packet_id:string, pattern_family:string, outcome:string, impact?:string, give_back_count?:int}>  $outcomes
     * @return array{schema:string, priority_adjustments:list<array<string,mixed>>, next_wave_hints:list<array<string,mixed>>, promoted:list<string>, demoted:list<string>}
     */
    public function learn(array $outcomes): array
    {
        // Accumulate deltas by pattern_family.
        $accumulated = [];
        $reasons = [];

        foreach ($outcomes as $o) {
            $family = (string) ($o['pattern_family'] ?? '');
            if ($family === '') {
                continue;
            }

            $outcome = (string) ($o['outcome'] ?? '');
            $impact = strtolower((string) ($o['impact'] ?? self::IMPACT_MEDIUM));
            $giveBackCount = max(1, (int) ($o['give_back_count'] ?? 1));

            $delta = match ($outcome) {
                self::OUTCOME_DELIVERED => match ($impact) {
                    self::IMPACT_HIGH   => self::DELTA_DELIVERED_HIGH,
                    self::IMPACT_LOW    => self::DELTA_DELIVERED_LOW,
                    default             => self::DELTA_DELIVERED_MEDIUM,
                },
                self::OUTCOME_GIVE_BACK => self::DELTA_GIVE_BACK_BASE * min($giveBackCount, 5),
                self::OUTCOME_PROXY     => self::DELTA_PROXY,
                default => 0.0,
            };

            $accumulated[$family] = ($accumulated[$family] ?? 0.0) + $delta;

            $reasons[$family][] = match ($outcome) {
                self::OUTCOME_DELIVERED => "delivered:{$impact}",
                self::OUTCOME_GIVE_BACK => "give_back:count:{$giveBackCount}",
                self::OUTCOME_PROXY     => 'proxy:no_capability_gain',
                default                 => "unknown_outcome:{$outcome}",
            };
        }

        $adjustments = [];
        $promoted = [];
        $demoted = [];

        foreach ($accumulated as $family => $totalDelta) {
            $clamped = max(-1.0, min(1.0, round($totalDelta, 4)));
            $reasonStr = implode(';', $reasons[$family] ?? []);

            $adjustments[] = [
                'pattern_family' => $family,
                'delta' => $clamped,
                'reason' => $reasonStr,
            ];

            if ($clamped >= self::PROMOTE_THRESHOLD) {
                $promoted[] = $family;
            } elseif ($clamped <= -self::PROMOTE_THRESHOLD) {
                $demoted[] = $family;
            }
        }

        $hints = $this->buildNextWaveHints($promoted, $demoted);

        return [
            'schema' => self::SCHEMA,
            'priority_adjustments' => array_values($adjustments),
            'next_wave_hints' => $hints,
            'promoted' => array_values($promoted),
            'demoted' => array_values($demoted),
        ];
    }

    /**
     * @param  list<string>  $promoted
     * @param  list<string>  $demoted
     * @return list<array{source_category:string, focus:string, priority:float}>
     */
    private function buildNextWaveHints(array $promoted, array $demoted): array
    {
        $hints = [];

        foreach ($promoted as $family) {
            $hints[] = [
                'source_category' => 'internal_patterns',
                'focus' => "expand_{$family}_patterns_that_delivered",
                'priority' => 0.8,
            ];
        }

        foreach ($demoted as $family) {
            $hints[] = [
                'source_category' => 'atlas_journals',
                'focus' => "investigate_why_{$family}_produces_give_back_or_proxy",
                'priority' => 0.6,
            ];
        }

        return $hints;
    }
}
