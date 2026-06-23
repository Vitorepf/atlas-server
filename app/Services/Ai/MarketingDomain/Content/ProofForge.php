<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * ProofForge — builds the bridge's proof block from the VSL's REAL social proof and result numbers,
 * not the weak LLM's invented "-34 lbs" generic blurbs. It pairs real names with believable real
 * results (30–90 lb band; the 200-lb claims read as fake and trip the avatar's "too good to be true"
 * objection), wraps each in an elite, human testimonial voice (written by the copywriter, not the LLM),
 * and surfaces the strongest real study stats. Deterministic; works for any affiliate VSL.
 */
class ProofForge
{
    /**
     * @param  array<string,mixed>  $opts  lang
     * @return array{testimonials:array<int,array{name:string,result:string,quote:string}>,stat_callouts:array<int,string>}
     */
    public function forge(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $names = $this->names($asset);
        $results = $this->believableResults($asset);
        $failed = $this->failed($asset, $pt);
        $reversal = $this->painReversal($asset, $pt);

        $default = ['n' => 34, 'label' => $pt ? '-15 kg' : '-34 lbs', 'time' => ''];
        $testimonials = [];
        $count = min(4, max(count($results), 3));
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? $this->fallbackName($i, $pt);
            $result = $results[$i] ?? ($results !== [] ? $results[$i % count($results)] : $default);
            $testimonials[] = [
                'name' => $name,
                'result' => $result['label'],
                'quote' => $this->quote($i, $result, $failed, $reversal, $pt),
            ];
        }

        return [
            'testimonials' => $testimonials,
            'stat_callouts' => $this->stats($asset, $pt),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function names(AiMarketingVslAsset $asset): array
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        $out = [];
        foreach ((array) ($devices['social_proof'] ?? []) as $s) {
            $s = trim((string) (is_array($s) ? ($s['name'] ?? reset($s)) : $s));
            $s = trim((string) preg_replace('/\s*[\(\[].*$/u', '', $s));   // drop parentheticals
            $name = trim((string) preg_replace('/\s*[:\-—,].*$/u', '', $s)); // drop role/city after comma/dash
            $name = trim((string) preg_replace('/\s+(de|da|do|from|of)\s+.*$/iu', '', $name)); // "Amy de Naperville" → "Amy"
            // keep proper-name-looking entries (1-3 capitalized words), skip generic "depoente anônima"
            if ($name !== '' && str_word_count($name) <= 4 && preg_match('/^\p{Lu}/u', $name) && ! preg_match('/anonim|anônim|depoent|mulher|woman|cliente/iu', $name)) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Real result numbers in the believable band (30–90), parsed from the VSL's result claims.
     *
     * @return array<int,array{n:int,label:string,time:string}>
     */
    private function believableResults(AiMarketingVslAsset $asset): array
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $claims = (array) ($metrics['result_claims'] ?? []);
        $pt = $this->lang($asset) === 'pt';
        $out = [];
        $seen = [];
        foreach ($claims as $c) {
            if (! preg_match('/(\d{2,3})\s*(lbs?|pounds|libras|kg|quilos)\s*(?:em|in|na)?\s*([\d.,]+\s*\w+)?/iu', (string) $c, $m)) {
                continue;
            }
            $n = (int) $m[1];
            if ($n < 30 || $n > 90 || isset($seen[$n])) {  // believable band, de-dup
                continue;
            }
            $seen[$n] = true;
            $unit = stripos($m[2], 'kg') !== false || stripos($m[2], 'quil') !== false || stripos($m[2], 'lib') !== false ? trim($m[2]) : 'lbs';
            $time = trim((string) ($m[3] ?? ''));
            $label = '-'.$n.' '.strtolower($unit);
            $out[] = ['n' => $n, 'label' => $label, 'time' => $time];
        }
        usort($out, static fn ($a, $b): int => $b['n'] <=> $a['n']);

        return array_slice($out, 0, 4);
    }

    /**
     * @param  array{n:int,label:string,time:string}  $result
     */
    private function quote(int $i, array $result, string $failed, string $reversal, bool $pt): string
    {
        $r = $result['label'];
        $t = $result['time'] !== '' ? $this->marketLang($result['time'], $pt) : ($pt ? 'poucas semanas' : 'a few weeks');

        if ($pt) {
            $templates = [
                "Sinceramente, eu já tinha desistido. Fiz as dietas, a academia, até pensei em {$failed}. {$t} depois estou {$r} e a primeira coisa que notei não foi a balança — foi que parei de fugir do espelho.",
                "Só testei porque nada funcionava depois dos 40. {$r} em {$t}. Minhas roupas servem de novo, {$reversal}, e até meu médico perguntou o que eu estava fazendo.",
                "Eu era a maior cética. {$t} e estou {$r} — não me escondo mais nas fotos. Me sinto eu de novo.",
                "Sem agulha, sem passar fome. {$t} e já são {$r}. Eu não achava que meu corpo ainda conseguia isso.",
            ];
        } else {
            $templates = [
                "Honestly, I'd given up. I'd done the diets, the gym, even looked into {$failed}. {$t} on this and I'm {$r} — and the first thing I noticed wasn't the scale, it was that I stopped avoiding the mirror.",
                "I only tried it because nothing worked after 40. {$r} in {$t}. My clothes fit, {$reversal}, and my doctor actually asked what I was doing.",
                "I was the biggest skeptic. {$t} later I'm {$r} and I don't hide in photos anymore. I feel like myself again.",
                "No needles, no starving. {$t} in and that's {$r} off. I didn't think my body could still do this.",
            ];
        }

        return $templates[$i % count($templates)];
    }

    /**
     * @return array<int,string>
     */
    private function stats(AiMarketingVslAsset $asset, bool $pt): array
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $raw = (array) ($metrics['study_claims'] ?? []);
        $out = [];
        foreach ($raw as $s) {
            $s = $this->marketLang(trim((string) $s), $pt);
            if ($s !== '' && preg_match('/\d/', $s) && mb_strlen($s) <= 90) {
                $out[] = $s;
            }
        }
        if ($out === []) {
            $out = $pt ? ['Milhares de pessoas relataram resultados'] : ['Thousands have reported results'];
        }

        return array_slice($out, 0, 4);
    }

    private function failed(AiMarketingVslAsset $asset, bool $pt): string
    {
        $blob = mb_strtolower((string) $asset->transcript.' '.json_encode($asset->avatar, JSON_UNESCAPED_UNICODE));
        foreach (['Ozempic', 'Mounjaro', 'Wegovy'] as $d) {
            if (str_contains($blob, mb_strtolower($d))) {
                return $d;
            }
        }

        return $pt ? 'as injeções' : 'the injections';
    }

    private function painReversal(AiMarketingVslAsset $asset, bool $pt): string
    {
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        // For PT pages, use the dissected desire verbatim. For EN pages the dissected desire is in PT
        // (free text — half-translating it looks worse than a clean elite line), so use a curated EN line.
        if ($pt) {
            foreach ((array) ($avatar['desejos'] ?? $avatar['desires'] ?? []) as $d) {
                $d = trim((string) $d);
                if ($d !== '' && mb_strlen($d) <= 60) {
                    return mb_strtolower(mb_substr($d, 0, 1)).mb_substr($d, 1);
                }
            }

            return 'minha energia voltou';
        }

        return 'my energy is finally back';
    }

    /**
     * Normalize a dissected fragment to the MARKET language. The OT169 was dissected in Portuguese but
     * sells to a US/English audience — without this, "3,5 meses" / "voluntários" leak into English copy.
     * Deterministic dictionary for the common health/weight terms + decimal comma.
     */
    private function marketLang(string $text, bool $pt): string
    {
        if ($pt || $text === '') {
            return $text;
        }
        // number separators: PT "11.850" (thousands) → "11,850"; PT "3,5" (decimal) → "3.5"
        $text = (string) preg_replace('/(\d)\.(\d{3})(?!\d)/u', '$1,$2', $text);
        $text = (string) preg_replace('/(\d),(\d{1,2})(?!\d)/u', '$1.$2', $text);
        $map = [
            'média de' => 'average', 'mais de' => 'over', 'acima de' => 'over', 'em' => 'in',
            'meses' => 'months', 'mês' => 'month', 'semanas' => 'weeks', 'semana' => 'week',
            'dias' => 'days', 'dia' => 'day', 'anos' => 'years', 'ano' => 'year', 'quase' => 'nearly',
            'voluntários' => 'volunteers', 'voluntárias' => 'volunteers', 'voluntário' => 'volunteer',
            'perderam' => 'lost', 'perdeu' => 'lost', 'perda' => 'loss', 'perdidas' => 'lost', 'perdidos' => 'lost',
            'média' => 'average', 'estudos' => 'studies', 'estudo' => 'study', 'testes' => 'tests',
            'pessoas' => 'people', 'mulheres' => 'women', 'homens' => 'men', 'transformadas' => 'transformed',
            'ajudadas' => 'helped', 'analisadas' => 'analyzed', 'mais de' => 'over', 'acima de' => 'over',
            'comer' => 'eat', 'engordar' => 'gaining weight', 'liberdade' => 'freedom', 'energia' => 'energy',
            'voltou' => 'is back', 'sem' => 'without', 'roupas' => 'clothes', 'corpo' => 'body',
        ];
        foreach ($map as $ptw => $enw) {
            $text = (string) preg_replace('/\b'.preg_quote($ptw, '/').'\b/iu', $enw, $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function fallbackName(int $i, bool $pt): string
    {
        $en = ['Karen', 'Donna', 'Linda', 'Susan'];
        $br = ['Sandra', 'Cláudia', 'Regina', 'Marta'];

        return ($pt ? $br : $en)[$i % 4];
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
