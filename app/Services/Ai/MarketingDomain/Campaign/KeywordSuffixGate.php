<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordSuffixGate — a regra #2 da dissecação das vendas reais: o ÚLTIMO SUBSTANTIVO da query re-classifica
 * o regime de CVR da MESMA raiz em 14-30×. POSSE vende, INFORMAÇÃO sangra. "jello diet" 31.95% → "jello diet
 * recipe" 2.20% (mesma raiz +1 palavra = 14× menos). O sufixo separa quem quer COMPRAR um objeto-de-posse
 * (diet/pen/supplement/drops) de quem quer fazer EM CASA DE GRAÇA (recipe/trick/treatment) — o caça-grátis
 * reproduzindo a ISCA de topo da própria VSL, não a decisão de compra.
 *
 * É VERDADE DE CVR provada por venda real (NÃO compliance/moralismo): o gate prioriza o comprador no scoring.
 * TRAVA ANTI-CAMPEÃ: NÃO rebaixa quando o sufixo faz parte de um owned-root coined ("gelatin trick" = o nome
 * do mecanismo) — testa se, removido o sufixo, o resto é uma raiz coined. Provider-free, determinístico.
 */
class KeywordSuffixGate
{
    /** substantivos de POSSE — quem digita quer um OBJETO pra comprar. */
    private const POSSESSION = [
        'diet', 'pen', 'pens', 'supplement', 'supplements', 'drops', 'drop', 'applicator', 'product',
        'pill', 'pills', 'capsule', 'capsules', 'serum', 'cream', 'gummies', 'gummy', 'formula', 'kit', 'tablets',
    ];

    /** substantivos de INFORMAÇÃO — quem digita quer fazer de graça / reproduzir a isca, não comprar. */
    private const INFORMATION = [
        'recipe', 'recipes', 'trick', 'tricks', 'treatment', 'remedy', 'remedies', 'cure', 'diy',
        'free', 'homemade', 'tutorial', 'guide',
    ];

    private const PROMOTE = 1.3;

    private const DEMOTE = 0.5;

    /**
     * @param  array<int,string>  $ownedRoots  raízes coined do ativo (mechanism/trick/slogan)
     * @return array{suffix:string,regime:string,multiplier:float,why:string}
     */
    public function classify(string $keyword, array $ownedRoots = []): array
    {
        $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', $keyword)));
        $last = $this->lastWord($k);

        // TRAVA ANTI-CAMPEÃ: keyword é um owned-root coined, OU removido o sufixo o resto é coined → não rebaixa
        $withoutSuffix = trim((string) preg_replace('/\s*\S+$/', '', $k));
        foreach ($ownedRoots as $r) {
            $r = mb_strtolower(trim((string) $r));
            if ($r !== '' && ($k === $r || $withoutSuffix === $r)) {
                return ['suffix' => $last, 'regime' => 'owned', 'multiplier' => 1.0, 'why' => "owned-root coined ('{$r}') — o sufixo não rebaixa a campeã"];
            }
        }

        if (in_array($last, self::POSSESSION, true)) {
            return ['suffix' => $last, 'regime' => 'possession', 'multiplier' => self::PROMOTE, 'why' => "substantivo de POSSE ('{$last}') = comprador de objeto, regime de COLHEITA"];
        }
        if (in_array($last, self::INFORMATION, true)) {
            return ['suffix' => $last, 'regime' => 'information', 'multiplier' => self::DEMOTE, 'why' => "substantivo de INFORMAÇÃO ('{$last}') = caça-grátis reproduzindo a isca da VSL, sangra"];
        }

        return ['suffix' => $last, 'regime' => 'neutral', 'multiplier' => 1.0, 'why' => 'sufixo neutro'];
    }

    private function lastWord(string $k): string
    {
        $parts = preg_split('/\s+/', $k) ?: [];

        return (string) (end($parts) ?: '');
    }
}
