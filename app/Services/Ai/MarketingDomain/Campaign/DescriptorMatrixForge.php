<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * DescriptorMatrixForge — a regra #4 da dissecação: o modificador descritivo SOBE o CVR (inverte a lógica de
 * cauda-longa). "orivelle" 28% → "orivelle fungus pen" 33% → "orivelle anti fungal pen" 37%. Cada morfema é
 * um pedaço A MAIS de memória da VSL que o cérebro reteve — quem lembra o nome E a categoria E o formato
 * recuperou um traço mais profundo, está mais perto do checkout. E quanto mais específica a célula, MENOS
 * disputada no leilão.
 *
 * Gera a matriz [coined] × [categoria/órgão] × [forma-fator] × [buy-intent] — usando SÓ o vocabulário que a
 * VSL usou pra descrever o produto (categoria coined pelo pitch), nunca termos de catálogo. Provider-free,
 * determinístico, dedup, sem o nome nu (esse já vem do enumerator).
 */
class DescriptorMatrixForge
{
    /** modificadores de intenção-de-compra (re-finder pronto pra comprar). */
    private const BUY_INTENT = ['review', 'reviews', 'buy', 'where to buy', 'price', 'cost', 'official', 'official website'];

    /**
     * @param  array<int,string>  $categories  categoria/órgão coined pela VSL (fungus, anti fungal, nail, brain, prostate)
     * @param  array<int,string>  $forms       forma-fator (pen, applicator, drops, pill, serum, solution)
     * @param  array<int,string>|null  $intents
     * @return array<int,string>
     */
    public function forge(string $coined, array $categories = [], array $forms = [], ?array $intents = null, int $cap = 200): array
    {
        $coined = mb_strtolower(trim(preg_replace('/\s+/', ' ', $coined)));
        if ($coined === '') {
            return [];
        }
        $cats = $this->clean($categories);
        $forms = $this->clean($forms);
        $intents = $this->clean($intents ?? self::BUY_INTENT);

        // bases descritivas (nunca o nome nu sozinho): coined+cat, coined+form, coined+cat+form
        $bases = [];
        foreach ($cats as $c) {
            $bases[] = "$coined $c";
        }
        foreach ($forms as $f) {
            $bases[] = "$coined $f";
        }
        foreach ($cats as $c) {
            foreach ($forms as $f) {
                $bases[] = "$coined $c $f"; // o padrão campeão: "orivelle anti fungal pen" 37%
            }
        }

        // cada base × buy-intent (re-finder de alta intenção)
        $out = $bases;
        foreach ($bases as $b) {
            foreach ($intents as $i) {
                $out[] = "$b $i";
            }
        }

        return array_slice(array_values(array_unique(array_filter($out, fn ($x) => trim($x) !== $coined))), 0, $cap);
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
