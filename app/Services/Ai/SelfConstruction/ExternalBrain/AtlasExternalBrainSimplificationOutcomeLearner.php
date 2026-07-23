<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Feedback gate for the simplification engine: reads what actually happened to completed
 * compression tasks — success, give_back, failure, poison, and regression outcomes — and turns
 * them into a priority delta per compression pattern (the grouping dimension a candidate came
 * from, e.g. 'stale_scaffold_delete' or 'purpose_tag_merge'). A pattern that keeps landing green
 * commits with real line reduction gets its priority raised; a pattern that keeps producing
 * give_backs, poison packets, or regressions gets it lowered — so the next origination batch
 * stops repeating what already failed.
 *
 * Input contract:
 *   outcomes: list<array{
 *     pattern?:              string,
 *     result?:               string  ('success'|'give_back'|'failure'|'poison'),
 *     commit_status?:        string  ('green'|'red', default 'green'),
 *     line_reduction?:       int,
 *     regression_detected?:  bool,
 *   }>
 *
 * Each outcome row contributes an independent, explicitly-reasoned delta to its pattern; a
 * regression penalty always applies on top of whatever the result itself contributed — a
 * "successful" commit that later regressed is never scored as a clean win.
 *
 * Pure PHP, deterministic, no I/O, no provider calls.
 */
final class AtlasExternalBrainSimplificationOutcomeLearner
{
    public const SCHEMA = 'atlas.external_brain.simplification_outcome_learner.v1';

    public const DIRECTION_INCREASE = 'increase';
    public const DIRECTION_DECREASE = 'decrease';
    public const DIRECTION_NEUTRAL  = 'neutral';

    /**
     * @param  array{outcomes?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, next_policy_adjustments:list<array<string,mixed>>}
     */
    public function learn(array $facts): array
    {
        $outcomes = is_array($facts['outcomes'] ?? null) ? $facts['outcomes'] : [];

        /** @var array<string,array{delta:float, reasons:list<string>}> $byPattern */
        $byPattern = [];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $pattern = trim((string) ($outcome['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }

            if (! isset($byPattern[$pattern])) {
                $byPattern[$pattern] = ['delta' => 0.0, 'reasons' => []];
            }

            $result       = strtolower(trim((string) ($outcome['result'] ?? '')));
            $commitStatus = strtolower(trim((string) ($outcome['commit_status'] ?? 'green')));
            $lineReduction = max(0, (int) ($outcome['line_reduction'] ?? 0));
            $regression   = (bool) ($outcome['regression_detected'] ?? false);

            if ($result === 'success' && $commitStatus !== 'red') {
                if ($lineReduction > 0) {
                    $byPattern[$pattern]['delta'] += 1.0;
                    $byPattern[$pattern]['reasons'][] = 'green_commit_with_real_line_reduction';
                } else {
                    $byPattern[$pattern]['delta'] += 0.25;
                    $byPattern[$pattern]['reasons'][] = 'green_commit_no_line_reduction';
                }
            } elseif ($result === 'give_back') {
                $byPattern[$pattern]['delta'] -= 1.0;
                $byPattern[$pattern]['reasons'][] = 'give_back_outcome';
            } elseif ($result === 'poison') {
                $byPattern[$pattern]['delta'] -= 2.0;
                $byPattern[$pattern]['reasons'][] = 'poison_pattern_outcome';
            } elseif ($result === 'failure') {
                $byPattern[$pattern]['delta'] -= 1.0;
                $byPattern[$pattern]['reasons'][] = 'failure_outcome';
            }

            if ($regression) {
                $byPattern[$pattern]['delta'] -= 2.0;
                $byPattern[$pattern]['reasons'][] = 'regression_detected';
            }
        }

        $adjustments = [];
        foreach ($byPattern as $pattern => $data) {
            $delta = round($data['delta'], 2);
            $direction = match (true) {
                $delta > 0.0 => self::DIRECTION_INCREASE,
                $delta < 0.0 => self::DIRECTION_DECREASE,
                default      => self::DIRECTION_NEUTRAL,
            };

            $adjustments[] = [
                'pattern'   => $pattern,
                'direction' => $direction,
                'delta'     => $delta,
                'reasons'   => array_values(array_unique($data['reasons'])),
            ];
        }

        usort($adjustments, static fn (array $a, array $b): int => strcmp((string) $a['pattern'], (string) $b['pattern']));

        return [
            'schema'                   => self::SCHEMA,
            'next_policy_adjustments'  => $adjustments,
        ];
    }
}
