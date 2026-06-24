<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * CoinedTokenForge — a face de GERAÇÃO da regra #8 (o MOAT): o ativo de longo prazo não é capturar a busca
 * de marca existente (leilão lotado), é FABRICAR a busca de amanhã. A teoria-mãe da dissecação diz que CVR ∝
 * improbabilidade-de-geração-independente; logo, pra plantar um re-finder futuro, coina-se um NOME que o
 * mercado não digitaria sem ter visto a VSL — e que você possui sozinho no leilão.
 *
 * Gera candidatos a token coinável a partir do(s) ingrediente(s)/benefício da oferta × veículos que a venda
 * real provou converterem (jello DIET 32%, gelatin TRICK, blue salt TRICK, coffee LOOPHOLE). Pontua cada um
 * por DEFENSIBILIDADE = riqueza do cone de mistype (quanto maior o vizinhança fonética que você vai possuir
 * sozinho, mais barato o tráfego futuro). Provider-free, determinístico. (Plantar o token no advertorial e
 * rastrear o re-find futuro é a frente live/Conversion-OS; aqui é só a GERAÇÃO do candidato.)
 */
class CoinedTokenForge
{
    /** veículos coined que a venda real provou (sufixo que vira parte do nome do mecanismo). */
    private const VEHICLES = ['diet', 'trick', 'protocol', 'method', 'ritual', 'hack', 'formula', 'secret', 'code', 'loophole', 'drops', 'salt trick'];

    /** veículos com VENDA REAL provada (jello diet 32%, gelatin trick, coffee loophole) — rankeiam primeiro. */
    private const PROVEN_VEHICLES = ['diet', 'trick', 'loophole', 'protocol', 'salt trick'];

    public function __construct(
        private readonly PhoneticMistypeForge $mistype = new PhoneticMistypeForge,
    ) {}

    /**
     * @param  array<int,string>  $ingredients  ingrediente/benefício/cor coined pela VSL (gelatin, pink salt, blue salt, coffee)
     * @param  array<int,string>|null  $vehicles
     * @return array<int,array{token:string,ingredient:string,vehicle:string,mistype_cone:int,ownability:int}>
     */
    public function forge(array $ingredients, ?array $vehicles = null): array
    {
        $vehicles = $this->clean($vehicles ?? self::VEHICLES);
        $out = [];
        foreach ($this->clean($ingredients) as $ing) {
            $head = $this->head($ing);
            $cone = count($this->mistype->cone($head)); // defensibilidade: cone rico = tráfego de mistype futuro barato
            foreach ($vehicles as $v) {
                $token = $ing.' '.$v;
                $out[$token] = [
                    'token' => $token,
                    'ingredient' => $ing,
                    'vehicle' => $v,
                    'mistype_cone' => $cone,
                    'ownability' => $this->ownability($ing, $cone) + (in_array($v, self::PROVEN_VEHICLES, true) ? 30 : 0),
                ];
            }
        }

        $list = array_values($out);
        // mais defensável primeiro (ownability desc), desempate estável pelo token
        usort($list, fn ($a, $b) => ($b['ownability'] <=> $a['ownability']) ?: strcmp($a['token'], $b['token']));

        return $list;
    }

    /** defensibilidade-prior: cone de mistype (tráfego futuro que você possui sozinho) + bônus de improbabilidade. */
    private function ownability(string $ingredient, int $cone): int
    {
        $words = count(preg_split('/\s+/', $ingredient) ?: []);
        $multiWordBonus = $words >= 2 ? 20 : 0; // combinação multi-palavra é mais improvável-de-gerar (mais ownable)

        return $cone + $multiWordBonus;
    }

    private function head(string $ingredient): string
    {
        $p = preg_split('/\s+/', $ingredient) ?: [];
        // o head pro cone de mistype = a palavra mais longa (a mais "coinável"/incomum)
        usort($p, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return (string) ($p[0] ?? $ingredient);
    }

    /** @param array<int,string> $a @return array<int,string> */
    private function clean(array $a): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), $a),
            fn ($s) => $s !== '',
        )));
    }
}
