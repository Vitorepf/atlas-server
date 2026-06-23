<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * VslThumbnailGenerator — builds the VSL player thumbnail (poster) as a self-contained SVG, straight
 * from the dissected VSL: the strongest result number, the common enemy, the named mechanism, the
 * "no injection" angle. A scroll-stopping "leaked report" cover with a play button — never a black
 * box. Deterministic; no image assets, no LLM.
 */
class VslThumbnailGenerator
{
    /**
     * @param  array<string,mixed>  $opts  lang
     */
    public function svg(AiMarketingVslAsset $asset, array $opts = []): string
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $angle = (string) ($opts['angle'] ?? 'number');

        [$line1, $line2, $sub, $badge, $accent] = $this->angleCopy($asset, $angle, $pt);
        $seen = $pt ? 'COMO VISTO NA TV' : 'AS SEEN IN THE NEWS';
        $urgency = $pt ? 'ASSISTA ANTES QUE SAIA DO AR' : 'WATCH BEFORE THIS STORY IS TAKEN DOWN';

        $line1 = $this->esc($line1);
        $line2 = $this->esc($line2);
        $sub = $this->esc($sub);

        return <<<SVG
<svg viewBox="0 0 680 383" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" role="img" aria-label="Watch the presentation">
<rect x="0" y="0" width="680" height="383" fill="#0b0c10"/>
<rect x="0" y="0" width="680" height="6" fill="#d8232a"/>
<rect x="0" y="350" width="680" height="33" fill="#d8232a"/>
<rect x="28" y="26" width="280" height="30" rx="4" fill="#d8232a"/>
<text x="44" y="46" font-family="Arial, Helvetica, sans-serif" font-size="15" font-weight="700" fill="#ffffff" letter-spacing="1.2">{$badge}</text>
<text x="652" y="46" text-anchor="end" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="700" fill="#9aa0aa" letter-spacing="1">{$seen}</text>
<text x="340" y="118" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="44" font-weight="800" fill="#ffffff">{$line1}</text>
<text x="340" y="162" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="38" font-weight="800" fill="{$accent}">{$line2}</text>
<text x="340" y="196" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="600" fill="#c9ccd3">{$sub}</text>
<circle cx="340" cy="272" r="46" fill="#ffffff" opacity="0.95"/>
<path d="M326 250 L326 294 L362 272 Z" fill="#d8232a"/>
<path d="M150 250 C 210 300, 270 300, 300 280" fill="none" stroke="{$accent}" stroke-width="4" stroke-linecap="round"/>
<path d="M296 272 L302 282 L308 270 Z" fill="{$accent}"/>
<text x="340" y="372" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="15" font-weight="800" fill="#ffffff" letter-spacing="1.2">{$urgency}</text>
</svg>
SVG;
    }

    /**
     * Generate distinct creative angles for split-testing (creative velocity is the #1 scale lever on
     * YouTube/Demand Gen). Returns angle-key => SVG. Authority angle only when a real authority exists.
     *
     * @param  array<string,mixed>  $opts  lang
     * @return array<string,string>
     */
    public function variants(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $out = [
            'number' => $this->svg($asset, $opts + ['angle' => 'number']),
            'leak' => $this->svg($asset, $opts + ['angle' => 'leak']),
        ];
        if ($this->authorityName($asset) !== '') {
            $out['authority'] = $this->svg($asset, $opts + ['angle' => 'authority']);
        }

        return $out;
    }

    /**
     * Headline copy + accent color for each thumbnail angle.
     *
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function angleCopy(AiMarketingVslAsset $asset, string $angle, bool $pt): array
    {
        $sub = $this->subline($asset, $pt);
        $authority = $this->authorityName($asset);
        if ($angle === 'authority' && $authority !== '') {
            return [
                $pt ? "O Segredo de {$authority}" : "{$authority}'s",
                $pt ? 'Para Derreter Gordura' : 'Fat-Melting Drop Secret',
                $sub,
                $pt ? 'EXCLUSIVO · COMO VISTO NA TV' : 'EXCLUSIVE · AS SEEN ON TV',
                '#4ea1ff',
            ];
        }
        if ($angle === 'leak') {
            return [
                $pt ? 'Tentaram Proibir' : 'They Tried to Ban',
                $pt ? 'Estas Gotas Caseiras' : 'These At-Home Drops',
                $sub,
                $pt ? 'VAZADO · ANTES QUE REMOVAM' : 'LEAKED · BEFORE IT IS REMOVED',
                '#ff5a3c',
            ];
        }
        // default: the number angle
        $number = $this->punchNumber($asset);

        return [
            $number !== '' ? ($pt ? "{$number} a Menos —" : "{$number} Gone —") : ($pt ? 'A História Que' : 'The Story They'),
            $this->line2($asset, $pt),
            $sub,
            $pt ? 'VAZADO · REPORTAGEM ESPECIAL' : 'LEAKED · SPECIAL REPORT',
            '#ffd23f',
        ];
    }

    /** First real authority name from the VSL (e.g. "Melania Trump", "Dr. Oz"), cleaned. */
    private function authorityName(AiMarketingVslAsset $asset): string
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        foreach ((array) ($devices['authority'] ?? []) as $a) {
            $a = trim((string) (is_array($a) ? ($a['name'] ?? reset($a)) : $a));
            $a = trim((string) preg_replace('/\s*[\(\[,:\-—].*$/u', '', $a));
            // proper-name-looking (1-3 words, capitalized), skip institutions like FDA/Big Pharma
            if ($a !== '' && str_word_count($a) >= 1 && str_word_count($a) <= 3 && preg_match('/^\p{Lu}/u', $a)
                && ! preg_match('/\b(fda|pharma|farma|gov|study|estudo|news|tv)\b/iu', $a)) {
                return mb_strimwidth($a, 0, 22, '');
            }
        }

        return '';
    }

    /** The strongest "-NN lbs" style number from the VSL's result claims/metrics. */
    private function punchNumber(AiMarketingVslAsset $asset): string
    {
        $blob = json_encode($asset->metrics, JSON_UNESCAPED_UNICODE).' '.json_encode($asset->claims, JSON_UNESCAPED_UNICODE).' '.(string) $asset->big_idea.' '.(string) $asset->core_promise;
        if (preg_match_all('/(\d{2,3})\s*(lbs?|pounds|libras|kg|quilos)/iu', (string) $blob, $m)) {
            $isMetric = static fn (string $u): bool => stripos($u, 'kg') !== false || stripos($u, 'quil') !== false || stripos($u, 'lib') !== false;
            // Thumbnail sweet spot: shocking but believable. Prefer the biggest number in [25,90];
            // a 200-lb testimonial reads as fake and kills credibility.
            $inBand = 0;
            $anyBest = 0;
            $unit = 'Lbs';
            foreach ($m[1] as $i => $raw) {
                $n = (int) $raw;
                if ($n <= 0 || $n > 250) {
                    continue;
                }
                if ($n > $anyBest) {
                    $anyBest = $n;
                    $unit = $isMetric($m[2][$i]) ? trim($m[2][$i]) : 'Lbs';
                }
                if ($n >= 25 && $n <= 90 && $n > $inBand) {
                    $inBand = $n;
                }
            }
            $best = $inBand ?: min($anyBest, 99);
            if ($best > 0) {
                return $best.' '.ucfirst(strtolower($unit));
            }
        }

        return '';
    }

    private function line2(AiMarketingVslAsset $asset, bool $pt): string
    {
        $blob = mb_strtolower((string) $asset->transcript.' '.(string) $asset->angle.' '.(string) $asset->big_idea.' '.json_encode($asset->keywords, JSON_UNESCAPED_UNICODE));
        $noInj = str_contains($blob, 'injection') || str_contains($blob, 'injeç') || str_contains($blob, 'no needle');
        $ozempic = str_contains($blob, 'ozempic') || str_contains($blob, 'mounjaro') || str_contains($blob, 'wegovy');
        if ($noInj && $ozempic) {
            return $pt ? 'Sem Injeção, Sem Ozempic' : 'No Injections, No Ozempic';
        }
        if ($noInj) {
            return $pt ? 'Sem Uma Única Injeção' : 'Without A Single Injection';
        }

        return $pt ? 'O Protocolo Caseiro' : 'The At-Home Protocol';
    }

    private function subline(AiMarketingVslAsset $asset, bool $pt): string
    {
        $mech = trim((string) $asset->mechanism_name) ?: ($pt ? 'protocolo' : 'protocol');
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        $enemy = ! empty($devices['conspiracy']);
        $mech = mb_strimwidth($mech, 0, 42, '');
        if ($enemy) {
            return $pt
                ? "O “{$mech}” que a Big Pharma não quer que você veja"
                : "The “{$mech}” Big Pharma won’t talk about";
        }

        return $pt ? "O “{$mech}” que está viralizando" : "The “{$mech}” everyone is talking about";
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br')) ? 'pt' : 'en';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
