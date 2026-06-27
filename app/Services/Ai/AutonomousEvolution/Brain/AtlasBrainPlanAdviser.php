<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PLAN ADVISER — reads a list of doctor findings + the current state's gates+score and emits the
 * SINGLE highest-priority recommended next action for the operator. Pure synthesis over the existing
 * perception suite; no new state, no mutation.
 *
 * Priority order is explicit (operator can audit):
 *   1. CRITICAL findings → handle first (e.g. gate_regression).
 *   2. WARN findings    → handle next.
 *   3. INFO findings    → ordered by an explicit ranking that puts ACTIONABLE ones first
 *      (frontier_empty + result_kind_starvation + score_ledger_regressing > histogram nudges).
 *
 * Pétreo: réu never edits the adviser (else it'd promote nudges it's good at).
 */
final class AtlasBrainPlanAdviser
{
    public const SCHEMA = 'atlas.brain.plan_adviser.v1';

    /** explicit per-info-code priority (higher = surfaced first). unlisted codes get 0. */
    private const INFO_PRIORITY = [
        'result_kind_starvation' => 90,
        'starvation_trend_worsening' => 85,
        'score_ledger_regressing' => 80,
        'frontier_empty' => 75,
        'cascade_rule_low_yield' => 70,
        'health_score_low' => 65,
        'evidence_stale' => 60,
        'brief_histogram_skewed' => 50,
        'hint_self_loop_dominant' => 45,
        'reflection_empty' => 30,
        'scope_signal_digest_dormant' => 20,
    ];

    /**
     * @param  list<array{severity:string, code:string, advice:string}>  $findings
     * @return array{schema:string, recommended:?array{severity:string, code:string, advice:string}, rationale:string}
     */
    public function advise(array $findings): array
    {
        $byCriticality = [
            'critical' => [],
            'warn' => [],
            'info' => [],
        ];
        foreach ($findings as $f) {
            $sev = (string) ($f['severity'] ?? '');
            if (isset($byCriticality[$sev])) {
                $byCriticality[$sev][] = $f;
            }
        }

        if ($byCriticality['critical'] !== []) {
            return ['schema' => self::SCHEMA, 'recommended' => $byCriticality['critical'][0], 'rationale' => 'first critical finding takes precedence'];
        }
        if ($byCriticality['warn'] !== []) {
            return ['schema' => self::SCHEMA, 'recommended' => $byCriticality['warn'][0], 'rationale' => 'no critical; first warn takes precedence'];
        }
        if ($byCriticality['info'] !== []) {
            usort($byCriticality['info'], fn (array $a, array $b): int => (self::INFO_PRIORITY[(string) $b['code']] ?? 0) <=> (self::INFO_PRIORITY[(string) $a['code']] ?? 0));

            return ['schema' => self::SCHEMA, 'recommended' => $byCriticality['info'][0], 'rationale' => 'no critical/warn; info ranked by explicit priority table'];
        }

        return ['schema' => self::SCHEMA, 'recommended' => null, 'rationale' => 'no findings — healthy; continue with the regular cycle'];
    }
}
