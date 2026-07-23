<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compact decision memory for the simplification engine: summarizes what actually happened to
 * every decided candidate — approved, held, or reverted — grouped by the compression pattern it
 * came from, and turns that history into next_decision_biases so future waves compound from what
 * worked instead of re-learning it every cycle.
 *
 * Input contract:
 *   {decisions: list<{
 *     pattern:      string,
 *     decision:     string  ('approved'|'hold'|'reverted'),
 *     hold_reason?: string,
 *     line_gain?:   int,
 *     fitness_gain?: float,
 *   }>}
 *
 * Bias derivation (per pattern, first match wins):
 *   penalize — ANY reversion recorded for this pattern. A pattern that had to be reverted once
 *              proved it is not safe to keep repeating, regardless of how many times it was
 *              approved before that.
 *   promote  — no reversions, approval_rate >= 0.70, AND positive measured gain (line or fitness).
 *              A pattern only earns promotion on PROVEN gain, never on approval count alone.
 *   neutral  — everything else (mixed signal, no gain evidence, or too few decisions to trust).
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationDecisionJournal
{
    public const SCHEMA = 'atlas.external_brain.simplification_decision_journal.v1';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_HOLD = 'hold';

    public const DECISION_REVERTED = 'reverted';

    public const BIAS_PROMOTE = 'promote';

    public const BIAS_PENALIZE = 'penalize';

    public const BIAS_NEUTRAL = 'neutral';

    private const PROMOTE_APPROVAL_RATE_THRESHOLD = 0.70;

    /**
     * @param  array{decisions?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, pattern_summaries:list<array<string,mixed>>, next_decision_biases:list<array<string,mixed>>}
     */
    public function summarize(array $facts): array
    {
        $decisions = (array) ($facts['decisions'] ?? []);

        /** @var array<string,array{total:int,approved:int,hold:int,hold_causes:array<string,true>,reverted:int,line_gain:int,fitness_gain:float}> $byPattern */
        $byPattern = [];

        foreach ($decisions as $row) {
            if (! is_array($row)) {
                continue;
            }

            $pattern = trim((string) ($row['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }

            if (! isset($byPattern[$pattern])) {
                $byPattern[$pattern] = ['total' => 0, 'approved' => 0, 'hold' => 0, 'hold_causes' => [], 'reverted' => 0, 'line_gain' => 0, 'fitness_gain' => 0.0];
            }

            $byPattern[$pattern]['total']++;

            $decision = (string) ($row['decision'] ?? '');
            match ($decision) {
                self::DECISION_APPROVED => $byPattern[$pattern]['approved']++,
                self::DECISION_HOLD => $byPattern[$pattern]['hold']++,
                self::DECISION_REVERTED => $byPattern[$pattern]['reverted']++,
                default => null,
            };

            if ($decision === self::DECISION_HOLD) {
                $cause = trim((string) ($row['hold_reason'] ?? ''));
                if ($cause !== '') {
                    $byPattern[$pattern]['hold_causes'][$cause] = true;
                }
            }

            $byPattern[$pattern]['line_gain'] += (int) ($row['line_gain'] ?? 0);
            $byPattern[$pattern]['fitness_gain'] += (float) ($row['fitness_gain'] ?? 0.0);
        }

        ksort($byPattern);

        $summaries = [];
        $biases = [];

        foreach ($byPattern as $pattern => $stats) {
            $approvalRate = $stats['total'] > 0 ? round($stats['approved'] / $stats['total'], 4) : 0.0;
            $holdCauses = array_keys($stats['hold_causes']);
            sort($holdCauses, SORT_STRING);

            $summaries[] = [
                'pattern' => $pattern,
                'total' => $stats['total'],
                'approved_count' => $stats['approved'],
                'approval_rate' => $approvalRate,
                'hold_count' => $stats['hold'],
                'hold_causes' => $holdCauses,
                'reverted_count' => $stats['reverted'],
                'measured_line_gain' => $stats['line_gain'],
                'measured_fitness_gain' => round($stats['fitness_gain'], 4),
            ];

            $biases[] = $this->bias($pattern, $stats, $approvalRate);
        }

        return [
            'schema' => self::SCHEMA,
            'pattern_summaries' => $summaries,
            'next_decision_biases' => $biases,
        ];
    }

    /**
     * @param  array{total:int,approved:int,hold:int,hold_causes:array<string,true>,reverted:int,line_gain:int,fitness_gain:float}  $stats
     * @return array{pattern:string, bias:string, reason:string}
     */
    private function bias(string $pattern, array $stats, float $approvalRate): array
    {
        if ($stats['reverted'] > 0) {
            return [
                'pattern' => $pattern,
                'bias' => self::BIAS_PENALIZE,
                'reason' => sprintf('reverted_count:%d>0', $stats['reverted']),
            ];
        }

        $hasPositiveGain = $stats['line_gain'] > 0 || $stats['fitness_gain'] > 0.0;
        if ($approvalRate >= self::PROMOTE_APPROVAL_RATE_THRESHOLD && $hasPositiveGain) {
            return [
                'pattern' => $pattern,
                'bias' => self::BIAS_PROMOTE,
                'reason' => sprintf('approval_rate:%.2f>=%.2f;measured_gain>0', $approvalRate, self::PROMOTE_APPROVAL_RATE_THRESHOLD),
            ];
        }

        return [
            'pattern' => $pattern,
            'bias' => self::BIAS_NEUTRAL,
            'reason' => 'no_proven_reason_to_promote_or_penalize',
        ];
    }
}
