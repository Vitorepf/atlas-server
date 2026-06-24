<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordVolumeSignal (L5 — volume/demanda real) — ingere o sinal de DEMANDA (que o motor hoje é cego)
 * e prioriza por demanda × intenção, pra não escalar um termo de 30 buscas/mês (relatório §2). O dado
 * vem de um export do Keyword Planner (CSV: keyword → searches, top-of-page bid) que o operador fornece —
 * NÃO exige campanha live/gasto, só o export da conta. Provider-free e determinístico.
 *
 * HONESTO (estrutural-vs-prior): sem o export, basis = 'unknown' — nunca fabrica volume. E mesmo com dado,
 * a demanda MODULA a prioridade, não VETA: o herói de mecanismo é low-volume por construção mas tem o
 * melhor CVR (volume real mascarado em close-variants, relatório §2) — então não se mata um high-score
 * por volume baixo; só se desempata a favor de quem TAMBÉM tem mercado.
 */
class KeywordVolumeSignal
{
    /**
     * @param  array<string,array{searches?:int,top_bid?:float}>  $volumeMap  keyword(lowercased) → planner data
     * @return array{volume:?int,top_of_page_bid:?float,demand_tier:string,basis:string,note:string}
     */
    public function assess(string $keyword, array $volumeMap = []): array
    {
        $kl = mb_strtolower(trim($keyword));
        $v = $volumeMap[$kl] ?? null;
        if ($v === null) {
            return [
                'volume' => null, 'top_of_page_bid' => null, 'demand_tier' => 'unknown', 'basis' => 'unknown',
                'note' => 'sem dado de volume — forneça o export do Keyword Planner pra ativar a priorização por demanda',
            ];
        }
        $searches = (int) ($v['searches'] ?? 0);
        $tier = match (true) {
            $searches >= 1000 => 'high',
            $searches >= 100 => 'medium',
            default => 'low',
        };

        return [
            'volume' => $searches,
            'top_of_page_bid' => isset($v['top_bid']) ? (float) $v['top_bid'] : null,
            'demand_tier' => $tier,
            'basis' => 'provided',
            'note' => 'volume é faixa do Planner (inclui close-variants); top-of-page bid é o sinal de preço, não o competition index',
        ];
    }

    /**
     * Re-rank scored keywords by score × demand (demand MODULA, não VETA). Sem volumeMap, mantém a ordem
     * por score e sinaliza intent_only (honesto: não dá pra ponderar demanda sem dado).
     *
     * @param  array<int,array<string,mixed>>  $scored
     * @param  array<string,array{searches?:int,top_bid?:float}>  $volumeMap
     * @return array{basis:string,ranked:array<int,array<string,mixed>>}
     */
    public function prioritize(array $scored, array $volumeMap = []): array
    {
        $factor = ['high' => 1.0, 'medium' => 0.75, 'low' => 0.45, 'unknown' => 0.6];
        $rows = [];
        foreach ($scored as $s) {
            $demand = $this->assess((string) ($s['keyword'] ?? ''), $volumeMap);
            $score = (int) ($s['score'] ?? 0);
            // 0.6 base + 0.4 demand → modula sem matar um high-score low-volume
            $priority = (int) round($score * (0.6 + 0.4 * ($factor[$demand['demand_tier']] ?? 0.6)));
            $rows[] = ['keyword' => $s['keyword'] ?? '', 'score' => $score, 'demand_tier' => $demand['demand_tier'], 'volume' => $demand['volume'], 'priority' => $priority];
        }
        // deterministic: priority desc, then keyword asc to break ties stably
        usort($rows, static fn ($a, $b): int => ($b['priority'] <=> $a['priority']) ?: strcmp((string) $a['keyword'], (string) $b['keyword']));

        return [
            'basis' => $volumeMap === [] ? 'intent_only' : 'demand_weighted',
            'ranked' => $rows,
        ];
    }
}
