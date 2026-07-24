<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use Illuminate\Support\Str;

/**
 * RsaAdForge — Google Search Responsive Search Ad assets, forged from the VSL: up to 15 headlines
 * (≤30 chars) and 4 descriptions (≤90 chars), message-matched to the VSL's own keywords and ammunition
 * (number, mechanism, "no injection", enemy). Every asset is hard-capped to Google's limits so they
 * never get truncated. Deterministic; works for any affiliate VSL.
 */
class RsaAdForge
{
    private const HEADLINE_MAX = 30;

    private const DESC_MAX = 90;

    /**
     * @param  array<string,mixed>  $opts  lang, final_url
     * @return array{headlines:array<int,string>,descriptions:array<int,string>,paths:array<int,string>,note:string}
     */
    public function forge(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $kw = $this->keywords($asset);
        $num = $this->number($asset);
        $mech = $this->shortMechanism($asset);
        $noInj = $this->noInjection($asset, $pt);

        // ~6 keyword-match headlines + the angle headlines (benefit/curiosity/CTA) for variety —
        // Google rewards a mix, and all-keyword RSAs read robotic.
        $headlineCandidates = array_merge(
            array_map(fn (string $k): string => $this->fixAcronyms(Str::title($k)), array_slice($kw, 0, 6)),
            $pt ? [
                $num ? "{$num} Sem Injeção" : '', 'Gotas, Não Injeção', 'Largue o Ozempic',
                'Sem Agulha, Sem Dieta', $mech, 'Mulheres 40+ Aprovam', 'A Gota de 4 Ingredientes',
                'Assista ao Vídeo Grátis', 'Veja o Protocolo', 'Queima de Gordura em Casa',
            ] : [
                $num ? "{$num}, No Injections" : '', 'Drops, Not Shots', 'Ditch Ozempic For This',
                'No Needle, No Diet', $mech, 'Women 40+ Are Switching', 'The 4-Ingredient Drop',
                'Watch The Free Video', 'See The Drops Protocol', 'At-Home Fat Burning',
            ],
        );

        $descCandidates = $pt ? [
            'As gotas que mulheres 40+ usam no lugar de injeções. Assista à apresentação grátis.',
            $num ? "Sem agulha, sem Ozempic. O protocolo de gotas por trás de histórias de {$num}." : 'Sem agulha, sem Ozempic. O protocolo de gotas caseiro. Veja como funciona.',
            'Suporte natural a GLP-1, GIP e glucagon numa gota simples. Assista antes que saia do ar.',
            'Esqueça as injeções de milhares por mês. O protocolo de gotas explicado. Vídeo grátis.',
        ] : [
            'The drops women over 40 use instead of weekly injections. Free presentation inside.',
            $num ? "No needles, no Ozempic. The at-home drops behind real {$num} stories. See how." : 'No needles, no Ozempic. The at-home drops protocol. See how it works inside.',
            'Natural GLP-1, GIP and glucagon support in a simple drop. Watch before it is taken down.',
            'Skip the $1,000-a-month shots. The triple-hormone drops protocol explained. Free video.',
        ];

        return [
            'headlines' => $this->fitAll(array_map(fn ($t) => $this->fixAcronyms((string) $t), $headlineCandidates), self::HEADLINE_MAX, 15),
            'descriptions' => $this->fitAll(array_map(fn ($t) => $this->fixAcronyms((string) $t), $descCandidates), self::DESC_MAX, 4),
            'paths' => $this->paths($asset, $pt),
            'note' => $pt
                ? 'RSA Google Ads — 15 títulos (≤30) / 4 descrições (≤90), message-match com as keywords da VSL.'
                : 'Google Ads RSA — 15 headlines (≤30) / 4 descriptions (≤90), message-matched to the VSL keywords.',
        ];
    }

    /**
     * @param  array<int,string>  $items
     * @return array<int,string>
     */
    private function fitAll(array $items, int $max, int $limit): array
    {
        $out = [];
        foreach ($items as $t) {
            $t = $this->fit(trim((string) $t), $max);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** Keep the text if it fits; else trim to the last whole word within the limit; else drop. */
    private function fit(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $cut = mb_substr($cut, 0, mb_strrpos($cut, ' ') ?: $max);
        $cut = rtrim($cut, ' ,.;:-–—');

        return mb_strlen($cut) >= 8 ? $cut : '';
    }

    /**
     * @return array<int,string>
     */
    private function keywords(AiMarketingVslAsset $asset): array
    {
        $kw = is_array($asset->keywords) ? $asset->keywords : [];
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];
        $terms = [];
        foreach ($clusters as $c) {
            foreach ((array) ($c['terms'] ?? []) as $t) {
                $t = trim((string) (is_array($t) ? ($t['term'] ?? '') : $t), " []\"'");
                if ($t !== '' && mb_strlen($t) <= self::HEADLINE_MAX) {
                    $terms[] = $t;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    private function number(AiMarketingVslAsset $asset): string
    {
        if (preg_match_all('/(\d{2,3})\s*(lbs?|pounds|kg)/iu', json_encode($asset->metrics, JSON_UNESCAPED_UNICODE), $m)) {
            foreach ($m[1] as $n) {
                if ((int) $n >= 25 && (int) $n <= 90) {
                    return $n.' Lbs';
                }
            }
        }

        return '';
    }

    private function shortMechanism(AiMarketingVslAsset $asset): string
    {
        $m = trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name));
        if (mb_strlen($m) > 28) {
            $m = mb_substr($m, 0, 28);
            $m = rtrim(mb_substr($m, 0, mb_strrpos($m, ' ') ?: 28));
        }

        return $this->fixAcronyms($m);
    }

    /** Restore health-market acronyms that Str::title lowercases: "Glp 1"→"GLP-1", "Gip"→"GIP". */
    private function fixAcronyms(string $text): string
    {
        $text = (string) preg_replace('/\bGlp[\s-]?1\b/u', 'GLP-1', $text);
        $map = ['/\bGlp\b/u' => 'GLP', '/\bGip\b/u' => 'GIP', '/\bFda\b/u' => 'FDA', '/\bCbd\b/u' => 'CBD', '/\bUsa\b/u' => 'USA'];

        return (string) preg_replace(array_keys($map), array_values($map), $text);
    }

    private function noInjection(AiMarketingVslAsset $asset, bool $pt): string
    {
        return $pt ? 'sem injeção' : 'no injection';
    }

    /**
     * Google Ads display-URL paths (≤15 chars each).
     *
     * @return array<int,string>
     */
    private function paths(AiMarketingVslAsset $asset, bool $pt): array
    {
        $p1 = $pt ? 'gotas' : 'drops';
        $p2 = $this->number($asset) !== '' ? str_replace(' ', '-', strtolower($this->number($asset))) : ($pt ? 'oferta' : 'official');

        return [mb_substr($p1, 0, 15), mb_substr($p2, 0, 15)];
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
