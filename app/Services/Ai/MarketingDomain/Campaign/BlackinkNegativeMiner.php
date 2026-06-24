<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use Illuminate\Support\Facades\DB;

/**
 * BlackinkNegativeMiner (L6 — exclusão aterrada em dinheiro real) — o espelho do harvester: este minera os
 * LOSERS reais (termos com tráfego e ZERO venda no Blackink) e destila os n-gramas que SÓ aparecem em quem
 * gastou-sem-vender → candidatos a NEGATIVO. Sob budget-cap, remover o desqualificado rende mais que atrair
 * (devolve o CPC inteiro pro budget recomprar urna boa) — é a metade "QUEM NÃO entra na urna" da missão.
 *
 * TRAVA DE SEGURANÇA PÉTREA (anti-campeã): um n-grama NUNCA vira negativo se aparece em QUALQUER termo que
 * converteu — não se bloqueia o que vende. Conn `nivor` READ-ONLY. Determinístico (ordem estável por waste).
 */
class BlackinkNegativeMiner
{
    private const CONNECTION = 'nivor';

    /** function-words sem intenção; "recipe/free/how" NÃO entram aqui — são sinal de negativo. */
    private const STOP = ['the', 'a', 'an', 'of', 'for', 'to', 'and', 'in', 'on', 'is', 'de', 'da', 'do', 'e', 'para', 'com', 'o', 'que'];

    /**
     * @param  array<int,array{term:string,clicks:int|float,conversions:int|float}>  $rows
     * @return array{negatives:array<int,array{ngram:string,wasted_clicks:int,loser_terms:int}>,protected_count:int,losers:int}
     */
    public function mine(array $rows, int $minWastedClicks = 50, int $loserMinClicks = 30): array
    {
        // 1) PROTEGIDOS: todo n-grama que aparece em QUALQUER termo que converteu (nunca bloquear o que vende).
        $protected = [];
        $losers = [];
        foreach ($rows as $r) {
            $term = mb_strtolower(trim((string) ($r['term'] ?? '')));
            if ($term === '' || str_contains($term, '{')) {
                continue;
            }
            $conv = (int) ($r['conversions'] ?? 0);
            $clicks = (int) ($r['clicks'] ?? 0);
            if ($conv > 0) {
                foreach ($this->ngrams($term) as $g) {
                    $protected[$g] = true;
                }
            } elseif ($clicks >= $loserMinClicks) {
                $losers[] = ['term' => $term, 'clicks' => $clicks];
            }
        }

        // 2) acumula cliques desperdiçados por n-grama dos losers, exceto os protegidos
        $waste = [];
        $loserCount = [];
        foreach ($losers as $l) {
            foreach ($this->ngrams($l['term']) as $g) {
                if (isset($protected[$g])) {
                    continue; // trava: co-ocorre com venda → jamais negativo
                }
                $waste[$g] = ($waste[$g] ?? 0) + $l['clicks'];
                $loserCount[$g] = ($loserCount[$g] ?? 0) + 1;
            }
        }

        $negatives = [];
        foreach ($waste as $g => $w) {
            if ($w < $minWastedClicks || ($loserCount[$g] ?? 0) < 2) {
                continue; // precisa de waste material E recorrência (≥2 termos) — não negativar por 1 fluke
            }
            $negatives[] = ['ngram' => $g, 'wasted_clicks' => $w, 'loser_terms' => $loserCount[$g]];
        }
        usort($negatives, fn ($a, $b) => $b['wasted_clicks'] <=> $a['wasted_clicks'] ?: strcmp($a['ngram'], $b['ngram']));

        return ['negatives' => $negatives, 'protected_count' => count($protected), 'losers' => count($losers)];
    }

    /** Harvest READ-ONLY: minera negativos dos losers reais do Blackink. */
    public function harvest(int $minWastedClicks = 50): array
    {
        $db = DB::connection(self::CONNECTION);
        $sessions = $db->table('tracking_sessions')
            ->whereNotNull('utm_term')->where('utm_term', '<>', '')
            ->select('utm_term', DB::raw('count(*) as clicks'), DB::raw('sum((is_converted)::int) as conv'))
            ->groupBy('utm_term')->get();
        $rows = [];
        foreach ($sessions as $s) {
            $rows[] = ['term' => (string) $s->utm_term, 'clicks' => (int) $s->clicks, 'conversions' => (int) $s->conv];
        }

        return $this->mine($rows, $minWastedClicks);
    }

    /** Unigramas + bigramas de conteúdo (sem function-words puros). @return array<int,string> */
    private function ngrams(string $term): array
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/u', ' ', $term)) ?: [],
            fn ($w) => $w !== '' && ! in_array($w, self::STOP, true),
        ));
        $grams = [];
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            $grams[$words[$i]] = true;
            if ($i + 1 < $n) {
                $grams[$words[$i].' '.$words[$i + 1]] = true;
            }
        }

        return array_keys($grams);
    }
}
