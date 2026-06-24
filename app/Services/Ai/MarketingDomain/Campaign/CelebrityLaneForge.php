<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * CelebrityLaneForge — a regra #3 da dissecação: celebridade-nicho + substantivo-de-POSSE é o MAIOR CVR da
 * conta inteira ("sanjay gupta brain health supplement" 36.14% em só 83 cliques). A MESMA celebridade com
 * sufixo de INFORMAÇÃO ("dr oz jello recipe") = 1.93% — 18× pior. A celebridade não salva o sufixo errado;
 * o sufixo carrega a venda. O OS hoje só conhece celebridade via KeywordAccountRiskSignal (flag de risco) —
 * FALTA a lane que GERA o re-finder de 36% CVR.
 *
 * Gera a matriz [celebridade] × [domínio/órgão] × [substantivo-de-posse] — NUNCA recipe/free/how-to. Prioriza
 * a celebridade MENOS saturada (dr oz virou termo de receita genérico, CVR no chão; o médico-de-nicho menos
 * batido converte alto). Provider-free, determinístico. (O risco de trademark continua válido como SINAL no
 * dossier high_risk → decisão do operador; aqui é só GERAÇÃO de CVR.)
 */
class CelebrityLaneForge
{
    /** substantivos de POSSE pra celebridade (alinhado ao KeywordSuffixGate) — quem busca quer COMPRAR. */
    private const POSSESSION_NOUNS = ['supplement', 'pill', 'pills', 'protocol', 'formula', 'drops', 'product', 'capsules', 'method', 'remedy'];

    /** celebridades SATURADAS (viraram termo de receita genérico) → CVR baixo, despriorizar. */
    private const SATURATED = ['dr oz', 'doctor oz', 'mehmet oz', 'oprah', 'dr phil'];

    /**
     * @param  array<int,string>  $celebrities  autoridades citadas na VSL (persuasion_devices.authority)
     * @param  array<int,string>  $domains      órgão/benefício do nicho (brain, memory, prostate, vision, blood sugar)
     * @param  array<int,string>|null  $nouns
     * @return array<int,array{keyword:string,celebrity:string,domain:string,noun:string,saturated:bool}>
     */
    public function forge(array $celebrities, array $domains = [], ?array $nouns = null): array
    {
        $nouns = $nouns ?? self::POSSESSION_NOUNS;
        $domainList = array_values(array_filter(array_map(fn ($d) => mb_strtolower(trim((string) $d)), $domains)));
        if ($domainList === []) {
            $domainList = ['']; // sem domínio → só celebridade × substantivo
        }

        $rows = [];
        foreach ($celebrities as $celeb) {
            $c = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $celeb)));
            if ($c === '') {
                continue;
            }
            $saturated = $this->isSaturated($c);
            foreach ($domainList as $d) {
                foreach ($nouns as $n) {
                    $n = mb_strtolower(trim((string) $n));
                    $kw = trim($c.' '.($d !== '' ? $d.' ' : '').$n);
                    $rows[$kw] = ['keyword' => $kw, 'celebrity' => $c, 'domain' => $d, 'noun' => $n, 'saturated' => $saturated];
                }
            }
        }

        $list = array_values($rows);
        // não-saturada primeiro (a aposta de CVR alto), desempate estável pela keyword
        usort($list, fn ($a, $b) => ($a['saturated'] <=> $b['saturated']) ?: strcmp($a['keyword'], $b['keyword']));

        return $list;
    }

    private function isSaturated(string $celeb): bool
    {
        foreach (self::SATURATED as $s) {
            if (str_contains($celeb, $s)) {
                return true;
            }
        }

        return false;
    }
}
