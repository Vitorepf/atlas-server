<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * NegativeKeywordForge — builds the LAYERED negative-keyword set at campaign-build time (report §4:
 * "negativa não é faxina, é infraestrutura de roteamento de budget"; sob budget-cap, remover o
 * desqualificado rende MAIS que atrair). Provider-free, PT-BR + EN.
 *
 * Two facts from the report materialised:
 *   • Negatives are DUMBER than keywords — they do NOT auto-match plurals/synonyms, so each base term is
 *     expanded into its morphological variants (singular/plural) here, by hand, as Google requires.
 *   • ANTI-CAMPEÃ gate — never negate a token that lives inside an OWNED ROOT (mechanism/trick/slogan):
 *     "blue salt trick recipe" must keep "recipe" as a buyer variant. This also structurally resolves the
 *     legacy 'recipe' collision (a generator emitting "...recipe" while a static list negated it).
 *
 * Layers: junk_universal · informational (T0) · price_freebie · polarity_negative.
 */
class NegativeKeywordForge
{
    private const JUNK_UNIVERSAL = [
        'diy', 'torrent', 'download', 'pdf', 'reddit', 'quora', 'wikipedia', 'forum', 'job', 'jobs',
        'emprego', 'vaga', 'salary', 'salário', 'curso', 'course', 'sample', 'amostra', 'meme', 'meme',
    ];

    private const INFORMATIONAL = [
        'o que é', 'o que e', 'what is', 'significado', 'meaning', 'definição', 'definicao', 'definition',
        'como funciona', 'how does it work', 'wikipedia', 'sintomas', 'symptoms', 'causas', 'causes',
    ];

    private const PRICE_FREEBIE = [
        'free', 'grátis', 'gratis', 'gratuito', 'cheap', 'barato', 'cupom', 'coupon', 'desconto',
        'discount', 'cheapest', 'genérico', 'generico', 'generic', 'caseiro', 'receita', 'recipe',
    ];

    private const POLARITY_NEGATIVE = [
        'scam', 'golpe', 'fraude', 'fraud', 'reclame aqui', 'reclamação', 'reclamacao', 'complaints',
        'lawsuit', 'processar', 'cancelar', 'reembolso', 'refund', 'side effects', 'efeitos colaterais',
    ];

    /**
     * @param  array<string,mixed>  $opts  protect: array<string> owned roots to never negate (anti-campeã)
     * @return array{layers:array<string,array<int,string>>,flat:array<int,string>,count:int,protected:array<int,string>}
     */
    public function forge(array $opts = []): array
    {
        $protect = array_values(array_filter(array_map(
            fn ($s) => mb_strtolower(trim((string) $s)),
            (array) ($opts['protect'] ?? []),
        ), fn ($s) => $s !== ''));

        $layers = [];
        foreach ([
            'junk_universal' => self::JUNK_UNIVERSAL,
            'informational' => self::INFORMATIONAL,
            'price_freebie' => self::PRICE_FREEBIE,
            'polarity_negative' => self::POLARITY_NEGATIVE,
        ] as $name => $base) {
            $expanded = $this->expandVariants($base);
            $layers[$name] = array_values(array_filter(
                $expanded,
                fn (string $t) => ! $this->protectedByOwnedRoot($t, $protect),
            ));
        }

        $flat = array_values(array_unique(array_merge(...array_values($layers))));

        return [
            'layers' => $layers,
            'flat' => $flat,
            'count' => count($flat),
            'protected' => $protect,
        ];
    }

    /**
     * Add singular/plural morphological variants (negatives don't auto-expand them).
     *
     * @param  array<int,string>  $base
     * @return array<int,string>
     */
    private function expandVariants(array $base): array
    {
        $out = [];
        foreach ($base as $term) {
            $term = mb_strtolower(trim($term));
            if ($term === '') {
                continue;
            }
            $out[$term] = true;
            // single-word: naive plural/singular pair (the common Google leak).
            if (! str_contains($term, ' ')) {
                if (str_ends_with($term, 's')) {
                    $out[mb_substr($term, 0, -1)] = true;
                } else {
                    $out[$term.'s'] = true;
                }
            }
        }

        return array_keys($out);
    }

    /** anti-campeã: a negative is dropped if it appears inside any protected owned root. */
    private function protectedByOwnedRoot(string $term, array $protect): bool
    {
        foreach ($protect as $root) {
            if ($root !== '' && str_contains($root, $term)) {
                return true;
            }
        }

        return false;
    }
}
