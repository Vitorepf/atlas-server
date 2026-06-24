<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * FunnelContinuityAuditor — structural-truth congruence across the funnel chain (Eixo 6).
 *
 * Two cross-niche brutal panels killed deterministic QUALITY scores as vocabulary proxies. The honest
 * deterministic lane is STRUCTURAL TRUTH: facts preserved or broken, not subjective quality. The biggest
 * trust/scent killer in a funnel is the BROKEN PROMISE: the ad hero-RESULT-claim that vanishes by the
 * landing page, or a core "free" contradicted by a later price for that same core offer (bait-and-switch).
 * These are FACTS — the number survives or it does not — so the checker is honest, not a proxy, and
 * ungameable for good (you cannot fake continuity).
 *
 * A third brutal panel confirmed the THESIS but caught the EXTRACTION crying wolf on the operator's own
 * everyday funnels (free-trial→paid, free shipping + product price, value-anchor, disclosed order-bump,
 * the "feel free" idiom, spelled-out carries) and a dead "%" branch. The operator's dispositive rule:
 * under-firing (missing a break) is honest and fine; FALSE-firing (accusing a break that isn't there)
 * destroys trust in the signal. So this is rebuilt CONSERVATIVE BY CONSTRUCTION — it only fires on the
 * unambiguous core case, and is proven silent on every legitimate funnel shape the panel surfaced.
 *
 * Honest limits (under-fire, never false-fire): blind to mechanism swap, emotional-promise drop, and
 * spelled-only claims it can't normalize. Those need the LLM verifier or the outcome ledger, not a regex.
 * Complement to MessageMatchScorer (keyword overlap). Provider-free, niche-agnostic.
 */
class FunnelContinuityAuditor
{
    /** Any money token — used for the price side of a bait-and-switch (not for hero promises). */
    private const PRICE_TOKEN = '/(?:\$|r\$|us\$)\s?\d[\d.,]*|\b\d{1,4}(?:[.,]\d{1,2})?\s?(?:dollars?|reais|usd|brl)\b/iu';

    /** A price that is NOT a charge for the core offer: optional/secondary, OR a value-anchor ("worth $X"). */
    private const OPTIONAL_PRICE_CTX = '/\b(?:optional|add[- ]?on|order bump|bonus|upsell|upgrade|workbook|toolkit|also get|add the|adicione|opcional|b[oô]nus|worth|normally|value|regularly|regular price|vale)\b/iu';

    /**
     * "free" that does not promise the CORE OFFER is free — so charging downstream is no contradiction:
     * idiom, shipping, trial, value-anchor, AND free CONTENT/lead-magnet (the standard VSL funnel: a free
     * presentation/video/guide leading to a paid product — the content is free, the product is not).
     */
    private const NONCORE_FREE_CTX = '/\bfeel free\b|\bfree to\b|\bfree shipping\b|\bfree trial\b|\bday free trial\b|\bfree \d+[- ]?day\b|\bcancel anytime\b|\bfor \d+[- ]?(?:day|days|week|weeks|month|months)\b|\bworth\b|\bnormally\b|\bvalue\b|\bregularly\b|\bfree (?:presentation|video|training|webinar|masterclass|workshop|guide|report|ebook|e-book|pdf|cheat ?sheet|demo|consultation|trial|sample|apresenta[çc][ãa]o|v[ií]deo|treinamento|webin[áa]rio|aula|guia|relat[óo]rio|amostra)\b/iu';

    /**
     * @param  array<string,string>  $stages  ordered [label => copy]; first entry = top of funnel (ad/headline)
     * @return array{breaks:array<int,array{key:string,name:string,detail:string}>,continuity:int,committed:array<int,string>,assessed:bool}
     */
    public function audit(array $stages): array
    {
        $stages = array_filter($stages, static fn ($t) => is_string($t) && trim($t) !== '');
        if (count($stages) < 2) {
            return ['breaks' => [], 'continuity' => 100, 'committed' => [], 'assessed' => false];
        }

        $labels = array_keys($stages);
        $texts = array_values($stages);
        $downstream = mb_strtolower(implode("\n", array_slice($texts, 1)));

        $breaks = [];

        // 1. DROPPED PROMISE — a quantified RESULT claim (weight/time/%/multiplier/income) in the top
        //    stage that no later stage carries (as digits OR spelled out). Pure prices are NOT promises.
        $heroClaims = $this->resultClaims($texts[0]);
        $preserved = 0;
        foreach ($heroClaims as $claim) {
            if ($this->carried($claim['num'], $downstream)) {
                $preserved++;
            } else {
                $breaks[] = [
                    'key' => 'dropped_promise',
                    'name' => 'Promessa do topo abandonada',
                    'detail' => 'a alegação "'.$claim['raw'].'" do '.$labels[0].' não reaparece downstream — '
                        .'quem clicou por isso perde o scent. Carregue o mesmo número/promessa na página.',
                ];
            }
        }

        // 2. PRICE SCENT BREAK — only the unambiguous core case: a stage says the CORE offer is free
        //    (not shipping/trial/value-anchor/idiom), and a LATER stage charges a CORE price (not an
        //    optional/bonus/upsell). Everything the panel flagged as a legit funnel is excluded.
        $priceBreak = $this->priceScentBreak($texts, $labels);
        if ($priceBreak !== null) {
            $breaks[] = $priceBreak;
        }

        $total = count($heroClaims) + ($priceBreak !== null ? 1 : 0);
        $kept = $preserved;
        $continuity = $total > 0 ? (int) round($kept / max(1, $total) * 100) : 100;

        return [
            'breaks' => $breaks,
            'continuity' => $continuity,
            'committed' => array_map(static fn ($c) => $c['raw'], $heroClaims),
            'assessed' => true,
        ];
    }

    /**
     * @param  array<int,string>  $texts
     * @param  array<int,string>  $labels
     * @return array{key:string,name:string,detail:string}|null
     */
    private function priceScentBreak(array $texts, array $labels): ?array
    {
        $freeStage = null;
        foreach ($texts as $i => $t) {
            if ($this->hasCoreFree($t)) {
                $freeStage = $i;
                break;
            }
        }
        if ($freeStage === null) {
            return null;
        }
        for ($j = $freeStage + 1; $j < count($texts); $j++) {
            if ($this->hasCorePrice($texts[$j])) {
                return [
                    'key' => 'price_scent_break',
                    'name' => 'Bait-and-switch de preço',
                    'detail' => 'o '.$labels[$freeStage].' promete a oferta-core "grátis" mas o '.$labels[$j]
                        .' cobra um preço pela oferta-core — contradição que quebra a confiança. Alinhe a promessa de preço ponta a ponta.',
                ];
            }
        }

        return null;
    }

    /** A genuine "core offer is free" claim — excludes idiom / shipping / trial / value-anchor. */
    private function hasCoreFree(string $text): bool
    {
        if (! preg_match('/\b(?:free|grátis|gratis|no cost|sem custo|zero cost|at no charge|de graça)\b/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        // Inspect every "free" occurrence; if ANY is a core-free (not in a non-core context), it counts.
        $offset = 0;
        while (preg_match('/\b(?:free|grátis|gratis|de graça)\b/iu', $text, $mm, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = (int) $mm[0][1];
            $window = mb_strtolower(substr($text, max(0, $pos - 20), 40));
            if (preg_match(self::NONCORE_FREE_CTX, $window) !== 1) {
                return true;
            }
            $offset = $pos + strlen($mm[0][0]);
        }

        return false;
    }

    /** A price charged for the CORE offer — excludes optional/bonus/upsell prices. */
    private function hasCorePrice(string $text): bool
    {
        if (! preg_match_all(self::PRICE_TOKEN, $text, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        foreach ($m[0] as $hit) {
            $pos = (int) $hit[1];
            $window = mb_strtolower(substr($text, max(0, $pos - 40), strlen($hit[0]) + 50));
            if (preg_match(self::OPTIONAL_PRICE_CTX, $window) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quantified RESULT claims (NOT prices): weight, time, %, multiplier, and income-context money.
     *
     * @return array<int,array{raw:string,num:string}>
     */
    private function resultClaims(string $text): array
    {
        $out = [];
        $seen = [];
        $add = function (string $raw, string $num) use (&$out, &$seen): void {
            $num = (string) preg_replace('/[.,]/', '', $num);
            if ($num === '' || isset($seen[$num.'|'.$raw])) {
                return;
            }
            $seen[$num.'|'.$raw] = true;
            $out[] = ['raw' => trim($raw), 'num' => $num];
        };

        // weight units — always a result claim
        if (preg_match_all('/(\d[\d.,]*)\s?(lbs?|pounds?|libras?|kg)\b/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $add($h[0], $h[1]);
            }
        }
        // time units — a result timeframe UNLESS it's an offer term (trial / guarantee / refund window)
        if (preg_match_all('/(\d[\d.,]*)\s?(days?|dias|weeks?|semanas?|months?|meses|years?|anos?)\b/iu', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $h) {
                $pos = (int) $h[0][1];
                $window = mb_strtolower(substr($text, max(0, $pos - 12), strlen($h[0][0]) + 28));
                if (preg_match('/\b(?:trial|guarantee|money[- ]?back|refund|warranty|garantia|reembolso|cancel)\b/iu', $window) === 1) {
                    continue; // "14 day trial", "60-day money-back" — offer term, not a hero promise
                }
                $add($h[0][0], $h[1][0]);
            }
        }
        // percentages (the previously-dead branch — % is a non-word char, so NO trailing \b)
        if (preg_match_all('/(\d[\d.,]*)\s?%/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $add($h[0], $h[1]);
            }
        }
        // multipliers (10x more leads)
        if (preg_match_all('/(\d[\d.,]*)\s?x\b/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $add($h[0], $h[1]);
            }
        }
        // income-context money only (make/earn $X, or $X per month) — a PRICE is not a promise
        if (preg_match_all('/(?:make|made|earn|earning|profit|income|ganhe|ganhar|fature|faturar|render)\b[^.\n]{0,30}?(?:\$|r\$|us\$)\s?(\d[\d.,]*)/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $add('$'.$h[1], $h[1]);
            }
        }
        if (preg_match_all('/(?:\$|r\$|us\$)\s?(\d[\d.,]*)[^.\n]{0,18}?(?:per month|a month|\/month|\/mo|per week|a week|per year|a year|por m[eê]s|por semana|por ano|por dia)/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $add('$'.$h[1].'/period', $h[1]);
            }
        }

        return $out;
    }

    /** Carried = the number appears downstream as a WHOLE token (not a substring) OR spelled out. */
    private function carried(string $num, string $downstream): bool
    {
        if ($num === '') {
            return true;
        }
        if (preg_match('/(?<![\d.,])'.preg_quote($num, '/').'(?![\d.,])/u', $downstream) === 1) {
            return true;
        }
        $word = $this->spell((int) $num);

        return $word !== '' && str_contains($downstream, $word);
    }

    /** Minimal integer→English words for carry (covers spelled-out hero numbers like "thirty"). */
    private function spell(int $n): string
    {
        if ($n < 0 || $n > 999) {
            return '';
        }
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            $w = $tens[intdiv($n, 10)];

            return $n % 10 === 0 ? $w : $w.'-'.$ones[$n % 10];
        }
        $w = $ones[intdiv($n, 100)].' hundred';

        return $n % 100 === 0 ? $w : $w.' '.$this->spell($n % 100);
    }
}
