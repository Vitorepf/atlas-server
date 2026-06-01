<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class CycleOutcomeSelectionSignalScorer
{
    private const SCHEMA_VERSION = 'atlas.loop.cycle_outcome_selection_signal.v1';

    private const MERGE_WEIGHT = 60;

    private const OUTCOME_MET_WEIGHT = 40;

    private const BLOCKER_WEIGHT = 80;

    private const REPAIR_EXHAUSTED_WEIGHT = 40;

    private const REPAIR_EXHAUSTED_THRESHOLD = 0.5;

    private const REPAIR_EXHAUSTED_PENALTY = 20;

    private const DELTA_BOUND = 100;

    private const CONFIDENCE_FULL_SAMPLE = 10;

    private const RATE_PRECISION = 4;

    /**
     * @param  array<string, list<array<string, mixed>>>  $historyByGapKind
     * @return array<string, mixed>
     */
    public function score(array $historyByGapKind): array
    {
        $signals = [];

        foreach ($historyByGapKind as $gapKind => $cycles) {
            $signals[(string) $gapKind] = $this->scoreGapKind((string) $gapKind, is_array($cycles) ? $cycles : []);
        }

        ksort($signals);

        $priorityDeltas = [];
        foreach ($signals as $gapKind => $signal) {
            $priorityDeltas[$gapKind] = $signal['priority_delta'];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'signals_by_gap_kind' => $signals,
            'priority_deltas' => $priorityDeltas,
            'best_gap_kind' => $this->bestGapKind($signals),
            'worst_gap_kind' => $this->worstGapKind($signals),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return array<string, mixed>
     */
    private function scoreGapKind(string $gapKind, array $cycles): array
    {
        $total = count($cycles);

        if ($total === 0) {
            return [
                'gap_kind' => $gapKind,
                'cycles' => 0,
                'merge_rate' => 0.0,
                'blocker_rate' => 0.0,
                'outcome_met_rate' => 0.0,
                'repair_exhausted_rate' => 0.0,
                'priority_delta' => 0,
                'confidence' => 0.0,
            ];
        }

        $merged = 0;
        $blocked = 0;
        $outcomeMet = 0;
        $repairExhausted = 0;

        foreach ($cycles as $cycle) {
            $cycle = is_array($cycle) ? $cycle : [];

            if ($this->flag($cycle, 'merged')) {
                $merged++;
            }

            if ($this->flag($cycle, 'blocked')) {
                $blocked++;
            }

            if ($this->flag($cycle, 'outcome_met')) {
                $outcomeMet++;
            }

            if ($this->flag($cycle, 'repair_exhausted')) {
                $repairExhausted++;
            }
        }

        $mergeRate = $this->rate($merged, $total);
        $blockerRate = $this->rate($blocked, $total);
        $outcomeMetRate = $this->rate($outcomeMet, $total);
        $repairExhaustedRate = $this->rate($repairExhausted, $total);

        return [
            'gap_kind' => $gapKind,
            'cycles' => $total,
            'merge_rate' => $mergeRate,
            'blocker_rate' => $blockerRate,
            'outcome_met_rate' => $outcomeMetRate,
            'repair_exhausted_rate' => $repairExhaustedRate,
            'priority_delta' => $this->priorityDelta($mergeRate, $blockerRate, $outcomeMetRate, $repairExhaustedRate),
            'confidence' => $this->confidence($total),
        ];
    }

    private function priorityDelta(
        float $mergeRate,
        float $blockerRate,
        float $outcomeMetRate,
        float $repairExhaustedRate,
    ): int {
        $raw = (self::MERGE_WEIGHT * $mergeRate)
            + (self::OUTCOME_MET_WEIGHT * $outcomeMetRate)
            - (self::BLOCKER_WEIGHT * $blockerRate)
            - (self::REPAIR_EXHAUSTED_WEIGHT * $repairExhaustedRate);

        if ($repairExhaustedRate > self::REPAIR_EXHAUSTED_THRESHOLD) {
            $raw -= self::REPAIR_EXHAUSTED_PENALTY;
        }

        $bounded = max((float) -self::DELTA_BOUND, min((float) self::DELTA_BOUND, $raw));

        return (int) round($bounded);
    }

    private function confidence(int $total): float
    {
        $ratio = $total / self::CONFIDENCE_FULL_SAMPLE;

        return round(min(1.0, $ratio), self::RATE_PRECISION);
    }

    private function rate(int $count, int $total): float
    {
        return round($count / $total, self::RATE_PRECISION);
    }

    /** @param array<string, mixed> $cycle */
    private function flag(array $cycle, string $key): bool
    {
        return ($cycle[$key] ?? false) === true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $signals
     */
    private function bestGapKind(array $signals): ?string
    {
        $best = null;
        $bestDelta = null;

        foreach ($signals as $gapKind => $signal) {
            $delta = $signal['priority_delta'];

            if ($bestDelta === null || $delta > $bestDelta) {
                $best = $gapKind;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, array<string, mixed>>  $signals
     */
    private function worstGapKind(array $signals): ?string
    {
        $worst = null;
        $worstDelta = null;

        foreach ($signals as $gapKind => $signal) {
            $delta = $signal['priority_delta'];

            if ($worstDelta === null || $delta < $worstDelta) {
                $worst = $gapKind;
                $worstDelta = $delta;
            }
        }

        return $worst;
    }
}
