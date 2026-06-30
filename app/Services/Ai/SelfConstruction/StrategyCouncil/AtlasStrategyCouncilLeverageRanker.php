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
 * Output: {schema_version,
 *   ranked:list<{candidate_id, reasons:list<string>, factors:array<string,mixed>, dominance_trace:string|null}>,
 *   rejected:list<{candidate_id, reasons:list<string>, rejected_proxy_summary?:array<string,mixed>}>}
 *
 * REJECTION rules:
 *   - empty evidence_refs ⇒ rejected (no real-leverage evidence).
 *   - proxy_signals \subset {novelty, task_count, line_churn, green_self_report} and NO real_levers ⇒ rejected.
 *
 * RANKING rules (lex order — autonomy_unlock DESC, capability_gap DESC, user_impact DESC,
 * waste_reduction DESC, dependency_count ASC, risk ASC, candidate_id ASC). Each factor appears as a
 * reason in the candidate's reasons list, so the operator can read why one beat another.
 *
 * dominance_trace: set after sorting; names which real-leverage factor(s) caused this candidate to
 * outrank the next one. "last_in_ranking" for the final item. Never contains composite score fields.
 *
 * rejected_proxy_summary: on proxy-only rejections; names the proxy signals present and which real
 * levers (autonomy_unlock, unblocks_count, capability_gap, waste_reduction, risk_reduction) were zero.
 * Never promotes task_count or novelty as positive evidence.
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
                $zeroLevers = array_keys(array_filter([
                    'autonomy_unlock'  => (int) ($c['autonomy_unlock'] ?? 0) === 0,
                    'unblocks_count'   => (int) ($c['unblocks_count'] ?? 0) === 0,
                    'capability_gap'   => (int) ($c['capability_gap'] ?? 0) === 0,
                    'waste_reduction'  => (int) ($c['waste_reduction'] ?? 0) === 0,
                    'risk_reduction'   => (int) ($c['risk_reduction'] ?? 0) === 0,
                ]));
                $rejected[] = [
                    'candidate_id'           => $id,
                    'reasons'                => $reasons,
                    'rejected_proxy_summary' => [
                        'proxy_signals_present' => $proxySignals,
                        'zero_real_levers'      => array_values($zeroLevers),
                        'verdict'               => 'no_real_leverage_evidence',
                    ],
                ];

                continue;
            }

            $campaignCount = (int) ($c['campaign_count'] ?? 0);
            $unlockFamilyCount = (int) ($c['unlock_family_count'] ?? 0);
            $crossCampaignCompounding = ($campaignCount >= 2 && $unlockFamilyCount >= 2) ? 1 : 0;

            $accepted[] = [
                'candidate_id' => $id,
                'factors' => [
                    'organ' => (string) ($c['organ'] ?? ''),
                    'cross_campaign_compounding' => $crossCampaignCompounding,
                    'campaign_count' => $campaignCount,
                    'unlock_family_count' => $unlockFamilyCount,
                    'capability_gap' => (int) ($c['capability_gap'] ?? 0),
                    'user_impact' => (int) ($c['user_impact'] ?? 0),
                    'autonomy_unlock' => (int) ($c['autonomy_unlock'] ?? 0),
                    'unblocks_count' => (int) ($c['unblocks_count'] ?? 0),
                    'waste_reduction' => (int) ($c['waste_reduction'] ?? 0),
                    'risk_reduction' => (int) ($c['risk_reduction'] ?? 0),
                    'risk' => (int) ($c['risk'] ?? 0),
                    'evidence_refs_count' => count($evidenceRefs),
                    'dependency_count' => (int) ($c['dependency_count'] ?? 0),
                ],
                'reasons' => [],
            ];
        }

        usort($accepted, function (array $a, array $b): int {
            return $b['factors']['cross_campaign_compounding'] <=> $a['factors']['cross_campaign_compounding']
                ?: $b['factors']['autonomy_unlock'] <=> $a['factors']['autonomy_unlock']
                ?: $b['factors']['unblocks_count'] <=> $a['factors']['unblocks_count']
                ?: $b['factors']['capability_gap'] <=> $a['factors']['capability_gap']
                ?: $b['factors']['user_impact'] <=> $a['factors']['user_impact']
                ?: $b['factors']['waste_reduction'] <=> $a['factors']['waste_reduction']
                ?: $b['factors']['risk_reduction'] <=> $a['factors']['risk_reduction']
                ?: $a['factors']['dependency_count'] <=> $b['factors']['dependency_count']
                ?: $a['factors']['risk'] <=> $b['factors']['risk']
                ?: strcmp($a['candidate_id'], $b['candidate_id']);
        });

        foreach ($accepted as $i => $row) {
            $accepted[$i]['reasons'] = [
                'cross_campaign_compounding='.$row['factors']['cross_campaign_compounding'],
                'campaign_count='.$row['factors']['campaign_count'],
                'unlock_family_count='.$row['factors']['unlock_family_count'],
                'autonomy_unlock='.$row['factors']['autonomy_unlock'],
                'unblocks_count='.$row['factors']['unblocks_count'],
                'capability_gap='.$row['factors']['capability_gap'],
                'user_impact='.$row['factors']['user_impact'],
                'waste_reduction='.$row['factors']['waste_reduction'],
                'risk_reduction='.$row['factors']['risk_reduction'],
                'dependency_count='.$row['factors']['dependency_count'],
                'risk='.$row['factors']['risk'],
                'evidence_refs_count='.$row['factors']['evidence_refs_count'],
            ];
            $accepted[$i]['dominance_trace'] = isset($accepted[$i + 1])
                ? $this->dominanceTrace($row['factors'], $accepted[$i + 1]['factors'])
                : 'last_in_ranking';
        }

        return [
            'schema_version' => self::SCHEMA,
            'ranked' => $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * Explain which real-leverage factors made $winner outrank $next.
     * Checks factors in ranking-priority order; stops after the first differentiator
     * (matching the actual sort logic so the trace is honest).
     *
     * @param  array<string,mixed>  $w   winner factors
     * @param  array<string,mixed>  $n   next factors
     */
    private function dominanceTrace(array $w, array $n): string
    {
        // DESC comparisons (higher is better)
        $descFactors = ['cross_campaign_compounding', 'autonomy_unlock', 'unblocks_count', 'capability_gap',
                        'user_impact', 'waste_reduction', 'risk_reduction'];
        foreach ($descFactors as $f) {
            $wv = (int) ($w[$f] ?? 0);
            $nv = (int) ($n[$f] ?? 0);
            if ($wv !== $nv) {
                return $f.'='.$wv.'_beats_'.$nv;
            }
        }

        // ASC comparisons (lower is better)
        foreach (['dependency_count', 'risk'] as $f) {
            $wv = (int) ($w[$f] ?? 0);
            $nv = (int) ($n[$f] ?? 0);
            if ($wv !== $nv) {
                return $f.'='.$wv.'_beats_'.$nv;
            }
        }

        // Tiebreak by candidate_id (not a factor, handled by the sort; trace is deterministic)
        return 'tied_on_all_factors_candidate_id_tiebreak';
    }
}
