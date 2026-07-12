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
 * Structural evidence factors (dependency_reach, recurrence, outcome_gap,
 * simplification_opportunity, verification_strength, blast_radius and evidence_freshness) plus
 * the existing optional factors are appended as the LOWEST-priority
 * tie-breakers, after every pre-existing factor and before the candidate_id ASC tiebreak — they
 * only ever differentiate candidates that already tie on every original factor, so no pre-existing
 * ranking outcome changes. Each candidate input field defaults to 0 (neutral) when omitted.
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

    /** claimable_per_active_worker at/below this is a worker-floor breach. */
    public const WORKER_FLOOR_THRESHOLD = 2.0;

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array{claimable_per_active_worker?: float|null}  $context
     * @return array<string,mixed>
     */
    public function rank(array $candidates, array $context = []): array
    {
        $accepted = [];
        $rejected = [];

        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $context) && $context['claimable_per_active_worker'] !== null
            ? (float) $context['claimable_per_active_worker']
            : null;
        $lowWorkerFloor = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::WORKER_FLOOR_THRESHOLD;

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
                || (int) ($c['autonomy_unlock'] ?? 0) > 0
                || (int) ($c['worker_continuity_delta'] ?? 0) > 0;
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
            $queueFeedOrRepair = (bool) ($c['is_queue_feed_or_repair'] ?? false);
            $workerFloorVeto = ($lowWorkerFloor && $queueFeedOrRepair) ? 1 : 0;
            $fallbackFreshness = (float) ($c['evidence_freshness'] ?? 0.0);
            $worldFreshness = max(0.0, min(1.0, (float) ($c['world_freshness'] ?? $fallbackFreshness)));
            $outcomeFreshness = max(0.0, min(1.0, (float) ($c['outcome_freshness'] ?? $fallbackFreshness)));
            $evidenceFreshness = min($worldFreshness, $outcomeFreshness);
            $outcomeSignal = strtolower((string) ($c['outcome_signal'] ?? 'unknown'));
            $outcomeSignalRank = match ($outcomeSignal) {
                'positive' => 2,
                'unknown' => 1,
                default => 0,
            };

            $accepted[] = [
                'candidate_id' => $id,
                'evidence_refs' => array_values(array_map('strval', $evidenceRefs)),
                'factors' => [
                    'organ' => (string) ($c['organ'] ?? ''),
                    'worker_floor_veto' => $workerFloorVeto,
                    'cross_campaign_compounding' => $crossCampaignCompounding,
                    'worker_continuity_delta' => (int) ($c['worker_continuity_delta'] ?? 0),
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
                    'compound_unlock' => (int) ($c['compound_unlock'] ?? 0),
                    'proof_cost' => (int) ($c['proof_cost'] ?? 0),
                    'implementation_risk' => (int) ($c['implementation_risk'] ?? 0),
                    'simplification_gain' => (int) ($c['simplification_gain'] ?? 0),
                    'worker_fit' => (int) ($c['worker_fit'] ?? 0),
                    'give_back_likelihood' => (int) ($c['give_back_likelihood'] ?? 0),
                    'dependency_reach' => (int) ($c['dependency_reach'] ?? 0),
                    'recurrence' => (int) ($c['recurrence'] ?? 0),
                    'outcome_gap' => (int) ($c['outcome_gap'] ?? 0),
                    'outcome_confidence' => round(max(0.0, min(1.0, (float) ($c['outcome_confidence'] ?? 0.0))), 6),
                    'outcome_signal' => $outcomeSignal,
                    'outcome_signal_rank' => $outcomeSignalRank,
                    'failure_recurrence' => max(0, (int) ($c['failure_recurrence'] ?? 0)),
                    'simplification_opportunity' => (int) ($c['simplification_opportunity'] ?? 0),
                    'verification_strength' => (int) ($c['verification_strength'] ?? 0),
                    'blast_radius' => (int) ($c['blast_radius'] ?? 0),
                    'world_freshness' => round($worldFreshness, 6),
                    'outcome_freshness' => round($outcomeFreshness, 6),
                    'evidence_freshness' => round($evidenceFreshness, 6),
                ],
                'reasons' => [],
            ];
        }

        usort($accepted, function (array $a, array $b): int {
            return $b['factors']['worker_floor_veto'] <=> $a['factors']['worker_floor_veto']
                ?: $b['factors']['cross_campaign_compounding'] <=> $a['factors']['cross_campaign_compounding']
                ?: $b['factors']['worker_continuity_delta'] <=> $a['factors']['worker_continuity_delta']
                ?: $b['factors']['autonomy_unlock'] <=> $a['factors']['autonomy_unlock']
                ?: $b['factors']['unblocks_count'] <=> $a['factors']['unblocks_count']
                ?: $b['factors']['capability_gap'] <=> $a['factors']['capability_gap']
                ?: $b['factors']['user_impact'] <=> $a['factors']['user_impact']
                ?: $b['factors']['waste_reduction'] <=> $a['factors']['waste_reduction']
                ?: $b['factors']['risk_reduction'] <=> $a['factors']['risk_reduction']
                ?: $b['factors']['evidence_refs_count'] <=> $a['factors']['evidence_refs_count']
                ?: $a['factors']['dependency_count'] <=> $b['factors']['dependency_count']
                ?: $a['factors']['risk'] <=> $b['factors']['risk']
                ?: $b['factors']['compound_unlock'] <=> $a['factors']['compound_unlock']
                ?: $b['factors']['simplification_gain'] <=> $a['factors']['simplification_gain']
                ?: $b['factors']['worker_fit'] <=> $a['factors']['worker_fit']
                ?: $a['factors']['proof_cost'] <=> $b['factors']['proof_cost']
                ?: $a['factors']['implementation_risk'] <=> $b['factors']['implementation_risk']
                ?: $a['factors']['give_back_likelihood'] <=> $b['factors']['give_back_likelihood']
                ?: $b['factors']['evidence_freshness'] <=> $a['factors']['evidence_freshness']
                ?: $b['factors']['outcome_confidence'] <=> $a['factors']['outcome_confidence']
                ?: $b['factors']['outcome_signal_rank'] <=> $a['factors']['outcome_signal_rank']
                ?: $b['factors']['dependency_reach'] <=> $a['factors']['dependency_reach']
                ?: $b['factors']['recurrence'] <=> $a['factors']['recurrence']
                ?: $b['factors']['outcome_gap'] <=> $a['factors']['outcome_gap']
                ?: $b['factors']['simplification_opportunity'] <=> $a['factors']['simplification_opportunity']
                ?: $b['factors']['verification_strength'] <=> $a['factors']['verification_strength']
                ?: $b['factors']['blast_radius'] <=> $a['factors']['blast_radius']
                ?: strcmp($a['candidate_id'], $b['candidate_id']);
        });

        foreach ($accepted as $i => $row) {
            $accepted[$i]['reasons'] = [
                'worker_floor_veto='.$row['factors']['worker_floor_veto'],
                'cross_campaign_compounding='.$row['factors']['cross_campaign_compounding'],
                'worker_continuity_delta='.$row['factors']['worker_continuity_delta'],
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
                'compound_unlock='.$row['factors']['compound_unlock'],
                'proof_cost='.$row['factors']['proof_cost'],
                'implementation_risk='.$row['factors']['implementation_risk'],
                'simplification_gain='.$row['factors']['simplification_gain'],
                'worker_fit='.$row['factors']['worker_fit'],
                'give_back_likelihood='.$row['factors']['give_back_likelihood'],
                'world_freshness='.$row['factors']['world_freshness'],
                'outcome_freshness='.$row['factors']['outcome_freshness'],
                'evidence_freshness='.$row['factors']['evidence_freshness'],
                'dependency_reach='.$row['factors']['dependency_reach'],
                'recurrence='.$row['factors']['recurrence'],
                'outcome_gap='.$row['factors']['outcome_gap'],
                'outcome_confidence='.$row['factors']['outcome_confidence'],
                'outcome_signal='.$row['factors']['outcome_signal'],
                'failure_recurrence='.$row['factors']['failure_recurrence'],
                'simplification_opportunity='.$row['factors']['simplification_opportunity'],
                'verification_strength='.$row['factors']['verification_strength'],
                'blast_radius='.$row['factors']['blast_radius'],
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
        $descFactors = ['worker_floor_veto', 'cross_campaign_compounding', 'worker_continuity_delta', 'autonomy_unlock', 'unblocks_count', 'capability_gap',
                        'user_impact', 'waste_reduction', 'risk_reduction', 'evidence_refs_count'];
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

        // The 6 additional lowest-priority factors — same DESC/ASC ordering as the usort chain.
        foreach (['compound_unlock', 'simplification_gain', 'worker_fit'] as $f) {
            $wv = (int) ($w[$f] ?? 0);
            $nv = (int) ($n[$f] ?? 0);
            if ($wv !== $nv) {
                return $f.'='.$wv.'_beats_'.$nv;
            }
        }
        foreach (['proof_cost', 'implementation_risk', 'give_back_likelihood'] as $f) {
            $wv = (int) ($w[$f] ?? 0);
            $nv = (int) ($n[$f] ?? 0);
            if ($wv !== $nv) {
                return $f.'='.$wv.'_beats_'.$nv;
            }
        }

        foreach (['evidence_freshness', 'outcome_confidence', 'outcome_signal_rank', 'dependency_reach', 'recurrence', 'outcome_gap', 'simplification_opportunity', 'verification_strength', 'blast_radius'] as $f) {
            $wv = (float) ($w[$f] ?? 0);
            $nv = (float) ($n[$f] ?? 0);
            if ($wv !== $nv) {
                return $f.'='.$wv.'_beats_'.$nv;
            }
        }

        // Tiebreak by candidate_id (not a factor, handled by the sort; trace is deterministic)
        return 'tied_on_all_factors_candidate_id_tiebreak';
    }
}
