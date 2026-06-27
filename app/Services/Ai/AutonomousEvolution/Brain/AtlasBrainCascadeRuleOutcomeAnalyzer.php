<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CASCADE-RULE OUTCOME ANALYZER — per-action_hint success rate, joined from the two existing stores
 * (no new writes). Reads the reflection stream (leverage_brief entries hold action_hint + cycle_id) and
 * joins with the done-set ledger (cycle status via snapshot_id === cycle_id). Emits per-hint counters.
 *
 * Why this is a real perception leap (not proxy): it turns the brain's own decisions into a measurable
 * signal — "hint X serves 80% of the time, hint Y refuses 70%" — that future compounding-path slices can
 * read to bias the cascade away from low-yield rules. Today the cascade is rule-by-rule blind to outcome.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the analyzer (else it'd inflate its own
 * win-rate by relabeling outcomes — Goodhart).
 */
final class AtlasBrainCascadeRuleOutcomeAnalyzer
{
    public const SCHEMA = 'atlas.brain.cascade_rule_outcome_analyzer.v1';

    /** done-set statuses that count as a win for the originator. */
    private const SERVED_STATUSES = ['served', 'seeded'];

    /** done-set statuses that count as a refusal (everything else is ignored as in-flight). */
    private const REFUSED_STATUSES = ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'];

    /**
     * @return array{schema:string, scope:string, joined_cycles:int, by_hint:list<array{hint:string, served:int, refused:int, total:int, served_rate_pct:int}>}
     */
    public function analyze(string $scope, AtlasBrainReflectionStream $stream, AtlasBrainDoneSetLedger $ledger): array
    {
        $scope = trim($scope);

        // Build cycle_id → action_hint map from leverage_brief reflections.
        $hintByCycle = [];
        foreach ($stream->forScope($scope) as $row) {
            $cycleId = trim((string) ($row['cycle_id'] ?? ''));
            if ($cycleId === '') {
                continue;
            }
            $signalsHint = trim((string) ($row['signals']['action_hint'] ?? ''));
            $text = (string) ($row['reflection'] ?? '');
            $hint = $signalsHint;
            if ($hint === '' && str_starts_with($text, 'leverage_brief: ')) {
                $rest = substr($text, strlen('leverage_brief: '));
                $cut = strpos($rest, ' — ');
                $hint = trim($cut === false ? $rest : substr($rest, 0, $cut));
            }
            if ($hint !== '') {
                // Last-write wins per cycle: a re-run for the same snapshot reflects the final hint.
                $hintByCycle[$cycleId] = $hint;
            }
        }

        // Walk every done-set row; join by snapshot_id → cycle_id.
        $counts = [];
        $joined = 0;
        foreach ($ledger->recentCycles(PHP_INT_MAX) as $row) {
            $cycleId = trim((string) ($row['snapshot_id'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));
            if ($cycleId === '' || ! isset($hintByCycle[$cycleId])) {
                continue;
            }
            $isServed = in_array($status, self::SERVED_STATUSES, true);
            $isRefused = in_array($status, self::REFUSED_STATUSES, true);
            if (! $isServed && ! $isRefused) {
                continue;
            }
            $hint = $hintByCycle[$cycleId];
            $counts[$hint] ??= ['served' => 0, 'refused' => 0];
            $counts[$hint][$isServed ? 'served' : 'refused']++;
            $joined++;
        }

        $rows = [];
        foreach ($counts as $hint => $c) {
            $total = $c['served'] + $c['refused'];
            $rows[] = [
                'hint' => (string) $hint,
                'served' => $c['served'],
                'refused' => $c['refused'],
                'total' => $total,
                'served_rate_pct' => $total > 0 ? (int) round(($c['served'] * 100) / $total) : 0,
            ];
        }
        // Deterministic sort: served_rate desc, then total desc (more evidence first), then hint asc.
        usort($rows, static fn (array $a, array $b): int => [$b['served_rate_pct'], $b['total'], $a['hint']] <=> [$a['served_rate_pct'], $a['total'], $b['hint']]);

        return [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'joined_cycles' => $joined,
            'by_hint' => $rows,
        ];
    }
}
