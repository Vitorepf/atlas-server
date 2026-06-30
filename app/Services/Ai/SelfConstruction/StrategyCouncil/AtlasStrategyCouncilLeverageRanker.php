<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure Strategy Council ranker. Orders candidate Self-Construction directions by REAL LEVERAGE EVIDENCE
 * — never by task volume or hype. Returns a transparent reason vector per candidate; NO single composite
 * score is computed or returned. Ties are broken deterministically by candidate_id ASC.
 *
 * Input candidate shape:
 *   {candidate_id, organ, capability_gap, user_impact, autonomy_unlock, waste_reduction, risk,
 *    evidence_refs:list<string>, dependency_count:int, proxy_signals?:list<string>}
 *
 * Output: {schema_version, ranked:list<{candidate_id, reasons:list<string>, factors:array<string,mixed>}>,
 *          rejected:list<{candidate_id, reasons:list<string>}>}
 *
 * REJECTION rules:
 *   - empty evidence_refs ⇒ rejected (no real-leverage evidence).
 *   - proxy_signals \subset {novelty, task_count, line_churn, green_self_report} and NO real_levers ⇒ rejected.
 *
 * RANKING rules (lex order — autonomy_unlock DESC, capability_gap DESC, user_impact DESC,
 * waste_reduction DESC, dependency_count ASC, risk ASC, candidate_id ASC). Each factor appears as a
 * reason in the candidate's reasons list, so the operator can read why one beat another.
 */
final class AtlasStrategyCouncilLeverageRanker
{
    public const SCHEMA = 'atlas.strategy_council.leverage_rank.v1';

    public const PROXY_ONLY_KINDS = ['novelty', 'task_count', 'line_churn', 'green_self_report'];

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    public function rank(array $candidates): array
    {
        $accepted = [];
        $rejected = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = (string) ($c['candidate_id'] ?? '');
            $evidenceRefs = array_values((array) ($c['evidence_refs'] ?? []));
            $proxySignals = array_values((array) ($c['proxy_signals'] ?? []));
            $reasons = [];

            if ($evidenceRefs === []) {
                $reasons[] = 'rejected:no_evidence_refs';
                $rejected[] = ['candidate_id' => $id, 'reasons' => $reasons];

                continue;
            }
            $proxyOnly = $proxySignals !== [] && array_values(array_diff($proxySignals, self::PROXY_ONLY_KINDS)) === [];
            $hasRealLever = (int) ($c['capability_gap'] ?? 0) > 0
                || (int) ($c['user_impact'] ?? 0) > 0
                || (int) ($c['autonomy_unlock'] ?? 0) > 0;
            if ($proxyOnly && ! $hasRealLever) {
                $reasons[] = 'rejected:proxy_signals_only:'.implode(',', $proxySignals);
                $rejected[] = ['candidate_id' => $id, 'reasons' => $reasons];

                continue;
            }

            $accepted[] = [
                'candidate_id' => $id,
                'factors' => [
                    'organ' => (string) ($c['organ'] ?? ''),
                    'capability_gap' => (int) ($c['capability_gap'] ?? 0),
                    'user_impact' => (int) ($c['user_impact'] ?? 0),
                    'autonomy_unlock' => (int) ($c['autonomy_unlock'] ?? 0),
                    'waste_reduction' => (int) ($c['waste_reduction'] ?? 0),
                    'risk' => (int) ($c['risk'] ?? 0),
                    'evidence_refs_count' => count($evidenceRefs),
                    'dependency_count' => (int) ($c['dependency_count'] ?? 0),
                ],
                'reasons' => [],
            ];
        }

        usort($accepted, function (array $a, array $b): int {
            return $b['factors']['autonomy_unlock'] <=> $a['factors']['autonomy_unlock']
                ?: $b['factors']['capability_gap'] <=> $a['factors']['capability_gap']
                ?: $b['factors']['user_impact'] <=> $a['factors']['user_impact']
                ?: $b['factors']['waste_reduction'] <=> $a['factors']['waste_reduction']
                ?: $a['factors']['dependency_count'] <=> $b['factors']['dependency_count']
                ?: $a['factors']['risk'] <=> $b['factors']['risk']
                ?: strcmp($a['candidate_id'], $b['candidate_id']);
        });

        foreach ($accepted as $i => $row) {
            $accepted[$i]['reasons'] = [
                'autonomy_unlock='.$row['factors']['autonomy_unlock'],
                'capability_gap='.$row['factors']['capability_gap'],
                'user_impact='.$row['factors']['user_impact'],
                'waste_reduction='.$row['factors']['waste_reduction'],
                'dependency_count='.$row['factors']['dependency_count'],
                'risk='.$row['factors']['risk'],
                'evidence_refs_count='.$row['factors']['evidence_refs_count'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'ranked' => $accepted,
            'rejected' => $rejected,
        ];
    }
}
