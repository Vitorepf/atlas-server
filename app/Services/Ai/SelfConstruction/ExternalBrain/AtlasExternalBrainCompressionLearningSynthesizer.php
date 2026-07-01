<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns simplification into a compounding system: instead of isolated per-wave ledgers, this
 * synthesizer aggregates audited outcome rows per pattern — wins, reversions, SLO misses, and
 * give_back — into a strategy_bias the next wave should act on. A pattern with a high win rate
 * and real measured gain is PROMOTED; a pattern whose reversions/SLO-misses/give-backs make up a
 * significant share of its outcomes is DEMOTED, regardless of how many wins it also has —
 * regression risk is never averaged away by unrelated successes.
 *
 * Input shape:
 *   { outcomes: list<{
 *       pattern?:     string,
 *       outcome?:     string,   // 'win'|'reverted'|'slo_miss'|'give_back'
 *       gain_score?:  float,    // only meaningful on 'win' rows
 *   }> }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionLearningSynthesizer
{
    public const SCHEMA = 'atlas.self_construction.external_brain.compression_learning_synthesizer.v1';

    public const BIAS_PROMOTE = 'promote';

    public const BIAS_DEMOTE = 'demote';

    public const BIAS_NEUTRAL = 'neutral';

    /** Share of reverted+slo_miss+give_back outcomes at/above this demotes the pattern. */
    private const NEGATIVE_RATE_DEMOTE_FLOOR = 0.30;

    private const WIN_RATE_PROMOTE_FLOOR = 0.70;

    private const AVG_GAIN_PROMOTE_FLOOR = 0.50;

    private const NEGATIVE_OUTCOMES = ['reverted', 'slo_miss', 'give_back'];

    /**
     * @param  array{outcomes?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, strategy_biases:array<string, array<string,mixed>>}
     */
    public function synthesize(array $facts): array
    {
        $outcomes = is_array($facts['outcomes'] ?? null) ? $facts['outcomes'] : [];

        $byPattern = [];
        foreach ($outcomes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pattern = trim((string) ($row['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $outcome = strtolower(trim((string) ($row['outcome'] ?? '')));
            $gain = (float) ($row['gain_score'] ?? 0.0);

            if (! isset($byPattern[$pattern])) {
                $byPattern[$pattern] = [
                    'win' => 0, 'reverted' => 0, 'slo_miss' => 0, 'give_back' => 0,
                    'total' => 0, 'gain_sum' => 0.0, 'win_count_for_gain' => 0,
                ];
            }

            $byPattern[$pattern]['total']++;
            if (in_array($outcome, ['win', ...self::NEGATIVE_OUTCOMES], true)) {
                $byPattern[$pattern][$outcome]++;
            }
            if ($outcome === 'win') {
                $byPattern[$pattern]['gain_sum'] += $gain;
                $byPattern[$pattern]['win_count_for_gain']++;
            }
        }

        $strategyBiases = [];
        foreach ($byPattern as $pattern => $stats) {
            $total = $stats['total'];
            $winRate = $total > 0 ? $stats['win'] / $total : 0.0;
            $negativeCount = $stats['reverted'] + $stats['slo_miss'] + $stats['give_back'];
            $negativeRate = $total > 0 ? $negativeCount / $total : 0.0;
            $avgGain = $stats['win_count_for_gain'] > 0 ? $stats['gain_sum'] / $stats['win_count_for_gain'] : 0.0;

            $bias = match (true) {
                $negativeRate >= self::NEGATIVE_RATE_DEMOTE_FLOOR => self::BIAS_DEMOTE,
                $winRate >= self::WIN_RATE_PROMOTE_FLOOR && $avgGain >= self::AVG_GAIN_PROMOTE_FLOOR => self::BIAS_PROMOTE,
                default => self::BIAS_NEUTRAL,
            };

            $strategyBiases[$pattern] = [
                'bias' => $bias,
                'win_rate' => round($winRate, 4),
                'negative_rate' => round($negativeRate, 4),
                'avg_gain' => round($avgGain, 4),
                'total' => $total,
            ];
        }

        ksort($strategyBiases, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'strategy_biases' => $strategyBiases,
        ];
    }
}
