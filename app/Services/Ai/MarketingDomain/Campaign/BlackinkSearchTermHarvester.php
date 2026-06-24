<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use Illuminate\Support\Facades\DB;

/**
 * BlackinkSearchTermHarvester (L2 — vetor de DESCOBERTA real) — a enumeração root×grid (L2 atual) é
 * sintética; este vetor aterra a descoberta no que as pessoas REALMENTE buscaram: o `utm_term` do Blackink
 * (conn `nivor` READ-ONLY, NUNCA escreve — pétreo [[blackink-nivor-production-safety]]). Cada termo real volta
 * classificado por intenção (L3) e já com seu outcome (clicks/conversions) — fundindo descoberta com prova.
 * É a diferença entre "achar que essas keywords existem" e "saber que gente buscou e o que vendeu".
 *
 * Núcleo PURO/testável (fromRows) + harvest read-only de runtime. Determinístico: mesma entrada → mesma
 * ordem (desempate estável por termo). Dedup por termo, sem placeholders DKI, sem vazio.
 */
class BlackinkSearchTermHarvester
{
    private const CONNECTION = 'nivor';

    private const TIER_RANK = ['T0' => 0, 'T1' => 1, 'T2' => 2, 'T3' => 3, 'T4' => 4];

    public function __construct(
        private readonly IntentLadderClassifier $intent = new IntentLadderClassifier,
    ) {}

    /**
     * Núcleo puro: linhas reais (term/clicks/conversions) → termos descobertos, classificados e priorizados.
     *
     * @param  array<int,array{term:string,clicks:int|float,conversions:int|float}>  $rows
     * @return array{terms:array<int,array<string,mixed>>,count:int,converters:int}
     */
    public function fromRows(array $rows): array
    {
        $byTerm = [];
        foreach ($rows as $r) {
            $term = mb_strtolower(trim((string) ($r['term'] ?? '')));
            if ($term === '' || str_contains($term, '{')) {
                continue; // vazio ou placeholder DKI ({keyword}) não é busca real
            }
            $clicks = (int) ($r['clicks'] ?? 0);
            $conv = (int) ($r['conversions'] ?? 0);
            if (isset($byTerm[$term])) { // dedup somando o tráfego de variantes idênticas
                $byTerm[$term]['clicks'] += $clicks;
                $byTerm[$term]['conversions'] += $conv;

                continue;
            }
            $cls = $this->intent->classify($term);
            $byTerm[$term] = [
                'term' => $term,
                'clicks' => $clicks,
                'conversions' => $conv,
                'intent_tier' => $cls['tier'],
                'intent_action' => $cls['action'],
                'converted' => $conv > 0,
            ];
        }

        $terms = array_values($byTerm);
        foreach ($terms as &$t) {
            $t['converted'] = $t['conversions'] > 0;
            $t['discovery_priority'] = $this->priority($t);
        }
        unset($t);

        // determinístico: prioridade desc, desempate estável pelo termo
        usort($terms, fn ($a, $b) => $b['discovery_priority'] <=> $a['discovery_priority'] ?: strcmp($a['term'], $b['term']));

        return [
            'terms' => $terms,
            'count' => count($terms),
            'converters' => count(array_filter($terms, fn ($t) => $t['converted'])),
        ];
    }

    /**
     * Harvest READ-ONLY do Blackink: os search-terms reais (utm_term) com tráfego ≥ minClicks.
     *
     * @return array{terms:array<int,array<string,mixed>>,count:int,converters:int}
     */
    public function harvest(int $minClicks = 5, int $topTerms = 3000): array
    {
        return $this->fromRows($this->readSearchTerms($minClicks, $topTerms));
    }

    /** Prioridade de descoberta determinística: quem VENDEU primeiro, depois intenção, depois demanda. */
    private function priority(array $t): int
    {
        $tierRank = self::TIER_RANK[$t['intent_tier']] ?? 0;

        return ($t['converted'] ? 100000 : 0) + $tierRank * 1000 + min(999, (int) $t['clicks']);
    }

    /** @return array<int,array{term:string,clicks:int,conversions:int}> */
    private function readSearchTerms(int $minClicks, int $topTerms): array
    {
        $db = DB::connection(self::CONNECTION);

        $sessions = $db->table('tracking_sessions')
            ->whereNotNull('utm_term')->where('utm_term', '<>', '')
            ->select('utm_term', DB::raw('count(*) as clicks'))
            ->groupBy('utm_term')->havingRaw('count(*) >= ?', [$minClicks])
            ->orderByDesc('clicks')->limit($topTerms)->get();

        $conversions = $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->where('cv.status', 'completed')->whereNotNull('ts.utm_term')->where('ts.utm_term', '<>', '')
            ->select('ts.utm_term', DB::raw('count(*) as c'))
            ->groupBy('ts.utm_term')->pluck('c', 'utm_term');

        $rows = [];
        foreach ($sessions as $s) {
            $rows[] = ['term' => (string) $s->utm_term, 'clicks' => (int) $s->clicks, 'conversions' => (int) ($conversions[$s->utm_term] ?? 0)];
        }

        return $rows;
    }
}
