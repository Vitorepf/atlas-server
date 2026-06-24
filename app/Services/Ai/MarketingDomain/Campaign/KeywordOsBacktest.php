<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordOsBacktest — valida o Keyword Intelligence OS contra VENDA REAL (read-only do Blackink, via
 * AiMarketingWinningPattern). Pega as keywords que DE FATO converteram (utm_term das sessões com
 * conversion status=completed) e mede a TAXA DE ACERTO: quantos winners reais o OS QUALIFICA (não
 * exclui). É a "prova cross-nicho vs winners reais" que a métrica exige — turn de "determinístico
 * provado" pra "VALIDADO contra venda real". Os MISSES (winner que o OS excluiria) são o alvo de
 * calibração: o sinal mais honesto de onde o motor ainda erra.
 *
 * Provider-free e determinístico: consome os padrões como DADO; nunca toca a produção (a leitura do
 * Blackink é feita upstream, read-only).
 */
class KeywordOsBacktest
{
    public function __construct(
        private readonly IntentLadderClassifier $intent = new IntentLadderClassifier,
    ) {}

    /**
     * @param  array<int,array{niche?:string,real_cvr?:mixed,converting_keywords?:array<int,mixed>}>  $patterns
     * @return array{overall_hit_rate:float,winners:int,qualified:int,per_niche:array<string,array<string,mixed>>,misses:array<int,array<string,mixed>>}
     */
    public function run(array $patterns): array
    {
        $perNiche = [];
        $allWinners = 0;
        $allQualified = 0;
        $misses = [];

        foreach ($patterns as $p) {
            $niche = (string) ($p['niche'] ?? 'unknown');
            $n = 0;
            $q = 0;
            foreach ((array) ($p['converting_keywords'] ?? []) as $k) {
                $term = mb_strtolower(trim((string) (is_array($k) ? ($k['term'] ?? '') : $k)));
                if ($term === '' || str_contains($term, '{keyword}') || str_contains($term, '{')) {
                    continue; // ignora vazamento de DKI / placeholder, não é uma keyword real
                }
                $n++;
                $cl = $this->intent->classify($term);
                if ($cl['action'] !== 'exclude') {
                    $q++; // o OS bidaria essa keyword (qualificada) — acerto contra a venda real
                } else {
                    $misses[] = ['niche' => $niche, 'term' => $term, 'tier' => $cl['tier'], 'polarity' => $cl['polarity'], 'intent_score' => $cl['intent_score']];
                }
            }
            if ($n > 0) {
                $perNiche[$niche] = ['winners' => $n, 'qualified' => $q, 'hit_rate' => round($q / $n, 3)];
            }
            $allWinners += $n;
            $allQualified += $q;
        }

        usort($misses, static fn ($a, $b): int => strcmp($a['niche'].$a['term'], $b['niche'].$b['term']));

        return [
            'overall_hit_rate' => $allWinners > 0 ? round($allQualified / $allWinners, 3) : 0.0,
            'winners' => $allWinners,
            'qualified' => $allQualified,
            'per_niche' => $perNiche,
            'misses' => $misses,
        ];
    }
}
