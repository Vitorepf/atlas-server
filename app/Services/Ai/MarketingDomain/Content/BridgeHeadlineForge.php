<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * BridgeHeadlineForge — elite direct-response headlines, forged DETERMINISTICALLY from the VSL's own
 * ammunition (the strongest result number, the named authority, the common enemy, the named mechanism,
 * the failed solutions, the avatar). This is the copywriter's voice crystallized into the engine so the
 * headline — the single most conversion-critical element — never depends on the weak LLM and never
 * comes out as generic "a hidden hormone story" AI mush.
 *
 * Each formula is a proven headline pattern; a formula is only emitted when it has enough real
 * ammunition (no empty "[Authority]" slots). Works for ANY affiliate VSL from its dissected fields.
 * Deterministic; no LLM.
 */
class BridgeHeadlineForge
{
    /**
     * @param  array<string,mixed>  $opts  lang ('en'|'pt')
     * @return array<int,string>  ranked best-first
     */
    public function forge(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $a = $this->ammo($asset);

        $h = [];
        // 1) Authority + result + differentiator (the "she said" news hook)
        if ($a['authority'] && $a['number'] && $a['mechanism']) {
            $h[] = $pt
                ? "As “{$a['mechanism']}” Que {$a['authority']} Diz Que Derreteram {$a['number']} — {$a['diff']}"
                : "The “{$a['mechanism']}” {$a['authority']} Says Melted {$a['number']} — {$a['diff']}";
        }
        // 2) Enemy secret (common enemy disarms the blame)
        if ($a['enemy'] && $a['avatar']) {
            $cat = $a['category'];
            $h[] = $pt
                ? "As Gotas de {$cat} Que a {$a['enemy']} Está Furiosa Que {$a['avatar']} Descobriram"
                : "The {$cat} Drops {$a['enemy']} Is Furious {$a['avatar']} Found Out About";
        }
        // 3) Curiosity question + identity + ditching the failed solution
        if ($a['avatar'] && $a['failed'] && $a['mechanism']) {
            $h[] = $pt
                ? "Por Que {$a['avatar']} Estão Largando {$a['failed']} Por Estas “{$a['mechanism']}”?"
                : "Why Are {$a['avatar']} Quietly Ditching {$a['failed']} For These “{$a['mechanism']}”?";
        }
        // 4) Number + mechanism + triple "no" (removes effort/sacrifice — the Value Equation)
        if ($a['number'] && $a['ingredients']) {
            $h[] = $pt
                ? "{$a['number']} a Menos Com {$a['ingredients']} Ingredientes Numa Gota — Sem Injeção, Sem Academia, Sem {$a['failedShort']}"
                : "{$a['number']} Gone Using a {$a['ingredients']}-Ingredient Drop — No Injections, No Gym, No {$a['failedShort']}";
        }
        // 5) Pain callout + reframe (it's not your fault, it's the mechanism)
        if ($a['avatarSingular'] && $a['pain'] && $a['mechanism']) {
            $h[] = $pt
                ? "Se Você é {$a['avatarSingular']} e {$a['pain']}, Esta “{$a['mechanism']}” Explica o Porquê"
                : "If You're {$a['avatarSingular']} and {$a['pain']}, This “{$a['mechanism']}” Explains Why";
        }
        // 6) Leaked / news hook with authority + number (pattern interrupt)
        if ($a['authority'] && $a['number']) {
            $h[] = $pt
                ? "Vazou: O Protocolo de Gotas Que {$a['authority']} Usou Para Perder {$a['number']} Sem Agulha"
                : "Leaked: The Drops Protocol {$a['authority']} Used to Drop {$a['number']} Without a Needle";
        }
        // 7) Skeptic disarm (first-person; speaks to the burned-before avatar)
        if ($a['mechanism']) {
            $h[] = $pt
                ? "Eu Tinha Desistido de Emagrecer — Até Ver o Que Estas “{$a['mechanism']}” Realmente Fazem"
                : "I'd Given Up on Losing Weight — Until I Saw What These “{$a['mechanism']}” Actually Do";
        }

        // de-dup, keep order (richest-ammo formulas already come first)
        return array_values(array_unique(array_filter(array_map('trim', $h))));
    }

    /**
     * @return array<string,string>
     */
    private function ammo(AiMarketingVslAsset $asset): array
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];

        return [
            'number' => $this->punchNumber($asset),
            'authority' => $this->firstName($devices['authority'][0] ?? ''),
            'mechanism' => $this->mechanism($asset),
            'enemy' => ! empty($devices['conspiracy']) ? ($this->lang($asset) === 'pt' ? 'Big Pharma' : 'Big Pharma') : '',
            'avatar' => $this->avatarLabel($asset),
            'avatarSingular' => $this->avatarLabel($asset, true),
            'failed' => $this->failed($asset, false),
            'failedShort' => $this->failed($asset, true),
            'ingredients' => (string) ($this->ingredientCount($asset) ?: ''),
            'category' => $this->category($asset),
            'diff' => $this->differentiator($asset),
            'pain' => $this->pain($asset),
        ];
    }

    private function mechanism(AiMarketingVslAsset $asset): string
    {
        $m = trim((string) $asset->mechanism_name);
        $m = (string) preg_replace('/\s*\(.*$/u', '', $m);

        return mb_strimwidth($m, 0, 40, '');
    }

    private function punchNumber(AiMarketingVslAsset $asset): string
    {
        $blob = json_encode($asset->metrics, JSON_UNESCAPED_UNICODE).' '.json_encode($asset->claims, JSON_UNESCAPED_UNICODE).' '.(string) $asset->big_idea;
        if (preg_match_all('/(\d{2,3})\s*(lbs?|pounds|libras|kg|quilos)/iu', (string) $blob, $m)) {
            $inBand = 0;
            $any = 0;
            $unit = 'Lbs';
            foreach ($m[1] as $i => $raw) {
                $n = (int) $raw;
                if ($n <= 0 || $n > 250) {
                    continue;
                }
                $any = max($any, $n);
                if ($n >= 25 && $n <= 90 && $n > $inBand) {
                    $inBand = $n;
                    $unit = stripos($m[2][$i], 'kg') !== false || stripos($m[2][$i], 'quil') !== false || stripos($m[2][$i], 'lib') !== false ? trim($m[2][$i]) : 'Lbs';
                }
            }
            $best = $inBand ?: min($any, 99);

            return $best > 0 ? $best.' '.ucfirst(strtolower($unit)) : '';
        }

        return '';
    }

    private function ingredientCount(AiMarketingVslAsset $asset): int
    {
        $blob = mb_strtolower((string) $asset->trick.' '.(string) $asset->big_idea.' '.(string) $asset->solution_mechanism);
        if (preg_match('/(\d)\s*(ingredient|natural|compound|ingrediente)/u', $blob, $m)) {
            return (int) $m[1];
        }
        $known = ['turmeric', 'curcumin', 'piperine', 'green tea', 'quercetin', 'berberine', 'resveratrol', 'cúrcuma', 'chá verde'];
        $c = 0;
        foreach ($known as $k) {
            if (str_contains($blob, $k)) {
                $c++;
            }
        }

        return $c >= 3 ? min($c, 4) : 0;
    }

    private function failed(AiMarketingVslAsset $asset, bool $short): string
    {
        $blob = mb_strtolower((string) $asset->transcript.' '.json_encode($asset->avatar, JSON_UNESCAPED_UNICODE));
        $hits = [];
        foreach (['Ozempic', 'Mounjaro', 'Wegovy'] as $drug) {
            if (str_contains($blob, mb_strtolower($drug))) {
                $hits[] = $drug;
            }
        }
        if ($hits === []) {
            return $short ? 'Pills' : ($this->lang($asset) === 'pt' ? 'as injeções' : 'the injections');
        }

        return $short ? $hits[0] : implode(($this->lang($asset) === 'pt' ? ' e ' : ' and '), array_slice($hits, 0, 2));
    }

    private function avatarLabel(AiMarketingVslAsset $asset, bool $singular = false): string
    {
        $pt = $this->lang($asset) === 'pt';
        [$who, $age] = $this->avatarParts($asset);
        if ($pt) {
            $whoTxt = $singular
                ? ($who === 'women' ? 'Mulher' : ($who === 'men' ? 'Homem' : 'Pessoa'))
                : ($who === 'women' ? 'Mulheres' : ($who === 'men' ? 'Homens' : 'Pessoas'));
            $ageTxt = $age !== '' ? 'Acima dos '.$age : '';
        } else {
            $whoTxt = $singular
                ? ($who === 'women' ? 'a Woman' : ($who === 'men' ? 'a Man' : 'Someone'))
                : ($who === 'women' ? 'Women' : ($who === 'men' ? 'Men' : 'People'));
            $ageTxt = $age !== '' ? 'Over '.$age : '';
        }

        return trim($whoTxt.' '.$ageTxt);
    }

    /**
     * @return array{0:string,1:string}  [who, age]
     */
    private function avatarParts(AiMarketingVslAsset $asset): array
    {
        $blob = mb_strtolower(json_encode($asset->avatar, JSON_UNESCAPED_UNICODE).' '.$asset->niche);
        $who = preg_match('/\b(women|woman|mulher|female)\b/u', $blob) ? 'women' : (preg_match('/\b(men|man|homem|male)\b/u', $blob) ? 'men' : 'people');
        $age = '';
        if (preg_match_all('/(\d{2})\s*\+|over\s*(\d{2})|acima dos?\s*(\d{2})|\b([456]0)s\b/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $set) {
                foreach (array_slice($set, 1) as $g) {
                    if ($g !== '' && (int) $g >= 30) {
                        $age = $g;
                        break 2;
                    }
                }
            }
        }

        return [$who, $age];
    }

    private function pain(AiMarketingVslAsset $asset): string
    {
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        $pains = (array) ($avatar['dores'] ?? $avatar['pains'] ?? []);
        foreach ($pains as $p) {
            $p = trim((string) $p);
            if ($p !== '' && mb_strlen($p) <= 55) {
                return mb_strtolower(mb_substr($p, 0, 1)).mb_substr($p, 1);
            }
        }

        return $this->lang($asset) === 'pt' ? 'seu corpo guarda tudo como gordura' : 'your body seems to store everything as fat';
    }

    private function category(AiMarketingVslAsset $asset): string
    {
        return match ($asset->niche) {
            'weight_loss' => $this->lang($asset) === 'pt' ? 'Queima-Gordura' : 'Fat-Burning',
            'prostate' => 'Prostate',
            'diabetes' => $this->lang($asset) === 'pt' ? 'Glicose' : 'Blood-Sugar',
            default => $this->lang($asset) === 'pt' ? 'Caseiras' : 'At-Home',
        };
    }

    private function differentiator(AiMarketingVslAsset $asset): string
    {
        $blob = mb_strtolower((string) $asset->transcript.' '.json_encode($asset->keywords, JSON_UNESCAPED_UNICODE));
        $noInj = str_contains($blob, 'injection') || str_contains($blob, 'injeç') || str_contains($blob, 'no needle');
        $ozempic = str_contains($blob, 'ozempic') || str_contains($blob, 'mounjaro');
        if ($noInj && $ozempic) {
            return $this->lang($asset) === 'pt' ? 'Sem Injeção, Sem Ozempic' : 'With No Injections, No Ozempic';
        }
        if ($noInj) {
            return $this->lang($asset) === 'pt' ? 'Sem Uma Única Injeção' : 'Without a Single Injection';
        }

        return $this->lang($asset) === 'pt' ? 'Em Casa, Sem Receita' : 'At Home, No Prescription';
    }

    private function firstName(mixed $v): string
    {
        $s = trim((string) (is_array($v) ? ($v['name'] ?? reset($v)) : $v));
        $s = (string) preg_replace('/\s*[\(\[].*$/u', '', $s);
        $s = trim((string) preg_replace('/\s*[:\-—].*$/u', '', $s));

        return str_word_count($s) <= 5 ? $s : '';
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
