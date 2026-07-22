<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

/**
 * META-STRATEGY DISTILLER — reads the AtlasLoopProjectionOutcomeLedger across campaigns and extracts the
 * cross-campaign STRATEGIES the Loop has learned: what to AVOID (parked / forbidden_target_petreo) and what it
 * has learned to CONVERGE. This is meta-learning — not "did this cycle work" but "what is the Loop getting
 * better at across campaigns".
 *
 * ANTI-GOODHART: a signal is promoted to a strategy ONLY with evidence from >=2 distinct campaigns AND >=3 total
 * occurrences; a single-campaign signal is recorded as single_campaign_noise (excluded_because
 * 'insufficient_cross_campaign_evidence') so one lucky run never masquerades as learning. Reads only — it never
 * mutates the ledger. Deterministic ordering.
 *
 * The ledger dependency is duck-typed (the real AtlasLoopProjectionOutcomeLedger is final, so tests inject a
 * compatible reader exposing outcomes()/read()).
 */
final class AtlasLoopV3MetaStrategyDistiller
{
    public const SCHEMA = 'atlas.loop.v3.meta_strategy_distiller.v1';

    public function __construct(private readonly object $ledger) {}

    /**
     * @param  list<string>  $campaignIds
     * @return array{strategies:list<array{kind:string,reason:?string,count:int,campaigns:int}>, single_campaign_noise:list<array{kind:string,reason:?string,count:int,campaigns:int,excluded_because:string}>, schema:string}
     */
    public function distill(array $campaignIds): array
    {
        $agg = []; // "kind|reason" => ['kind'=>, 'reason'=>, 'count'=>int, 'campaigns'=>array<string,true>]

        foreach ($campaignIds as $campaignId) {
            $campaignId = (string) $campaignId;
            foreach ($this->rowsFor($campaignId) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $status = (string) ($row['status'] ?? '');
                $reasonRaw = (string) ($row['reason'] ?? '');

                if ($status === 'parked' && $reasonRaw === 'forbidden_target_petreo') {
                    $kind = 'avoid';
                } elseif ($status === 'converged') {
                    $kind = 'converge';
                } else {
                    continue; // not a tracked strategy signal
                }

                $key = $kind.'|'.$reasonRaw;
                if (! isset($agg[$key])) {
                    $agg[$key] = ['kind' => $kind, 'reason' => $reasonRaw !== '' ? $reasonRaw : null, 'count' => 0, 'campaigns' => []];
                }
                $agg[$key]['count']++;
                $agg[$key]['campaigns'][$campaignId] = true;
            }
        }

        $strategies = [];
        $noise = [];
        foreach ($agg as $entry) {
            $campaigns = count($entry['campaigns']);
            $base = ['kind' => $entry['kind'], 'reason' => $entry['reason'], 'count' => $entry['count'], 'campaigns' => $campaigns];

            if ($campaigns >= 2 && $entry['count'] >= 3) {
                $strategies[] = $base;
            } else {
                $noise[] = $base + ['excluded_because' => 'insufficient_cross_campaign_evidence'];
            }
        }

        $cmp = static fn (array $a, array $b): int => [$b['count'], (string) $a['kind'], (string) ($a['reason'] ?? '')]
            <=> [$a['count'], (string) $b['kind'], (string) ($b['reason'] ?? '')];
        usort($strategies, $cmp);
        usort($noise, $cmp);

        return [
            'strategies' => $strategies,
            'single_campaign_noise' => $noise,
            'schema' => self::SCHEMA,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function rowsFor(string $campaignId): array
    {
        if (method_exists($this->ledger, 'outcomes')) {
            return array_values((array) $this->ledger->outcomes($campaignId));
        }
        if (method_exists($this->ledger, 'read')) {
            return array_values((array) $this->ledger->read($campaignId));
        }

        return [];
    }
}
