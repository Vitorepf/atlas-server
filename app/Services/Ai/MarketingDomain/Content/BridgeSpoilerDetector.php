<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * BridgeSpoilerDetector — the single most important conversion guard for a BRIDGE/advertorial that
 * feeds a LONG VSL. A bridge's only job is to warm the lead and hand it to the VSL still curious. A
 * long VSL (60-90 min) spends its first ~8 minutes on hook + emotional warmup and deliberately
 * withholds the product name, the physical form, the named ingredients, the price, the scarcity
 * numbers, the guarantee, and the detailed competitor comparison until the PITCH (often 30-40 min in).
 *
 * If the bridge reveals any of that, it spoils the VSL: the viewer arrives already knowing everything,
 * the 38-minute warmup becomes redundant, and they close the tab. This is a silent, structural
 * conversion killer that NO marker library catches — the copy can score "elite" and still bleak.
 *
 * The detector builds a spoiler catalog from the asset (product name, form, ingredients, price,
 * scarcity, guarantee) and — when the transcript is available — stamps each term with the exact
 * second the VSL itself reveals it, so a leak reads "Berberine — the VSL reveals it at 30:52; your
 * bridge shows it on the page." Provider-free.
 */
class BridgeSpoilerDetector
{
    /** Physical-form words that betray HOW the product is taken — pure VSL-reveal territory. */
    private const FORM_LEXICON = [
        'drop under the tongue', 'drops under the tongue', 'sublingual', 'liquid drop', 'the drops',
        'a drop of', 'one drop', 'two drops', 'capsule', 'capsules', 'pill', 'pills', 'powder',
        'gummy', 'gummies', 'tablet', 'tablets', 'sachet', 'tincture',
    ];

    /** Markers of a detailed competitor side-by-side — the VSL's job, not the bridge's. */
    private const DETAILED_COMPARISON_MARKERS = [
        'not the same molecule', 'same molecule', 'synthetic peptide', 'pound-for-pound',
        'ingredient-for-ingredient', 'ingredient for ingredient', 'side-by-side', 'side by side',
        'head-to-head', 'head to head',
    ];

    /**
     * @param  array<int,array{term:string,category:string,severity:string,is_regex?:bool}>  $catalog
     * @return array{leaks:array<int,array<string,mixed>>,n:int,verdict:string,worst:string}
     */
    public function inspect(string $bridgeCopy, array $catalog, ?string $transcript = null, float $charsPerSec = 16.1): array
    {
        $hay = mb_strtolower($bridgeCopy);
        $leaks = [];
        $seen = [];

        foreach ($catalog as $entry) {
            $term = (string) $entry['term'];
            $isRegex = (bool) ($entry['is_regex'] ?? str_starts_with($term, '/'));
            $needle = mb_strtolower($term);

            $hit = $isRegex ? (bool) @preg_match($term, $bridgeCopy) : str_contains($hay, $needle);
            if (! $hit) {
                continue;
            }
            $dedupKey = $entry['category'].'|'.$needle;
            if (isset($seen[$dedupKey])) {
                continue;
            }
            $seen[$dedupKey] = true;

            $revealAt = $transcript !== null && ! $isRegex
                ? $this->revealSecond($transcript, $term, $charsPerSec)
                : null;

            $leaks[] = [
                'term' => $term,
                'category' => $entry['category'],
                'severity' => $entry['severity'],
                'vsl_reveals_at' => $revealAt !== null ? $this->mmss($revealAt) : null,
                'vsl_reveals_at_seconds' => $revealAt,
                'fix' => $this->fixFor($entry['category']),
            ];
        }

        // Order by severity then by how late the VSL reveals it (later reveal = worse to spoil).
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($leaks, function ($a, $b) use ($rank) {
            $s = ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);

            return $s !== 0 ? $s : (($b['vsl_reveals_at_seconds'] ?? 0) <=> ($a['vsl_reveals_at_seconds'] ?? 0));
        });

        $worst = $leaks[0]['severity'] ?? 'none';
        $hasCritical = $worst === 'critical';

        return [
            'leaks' => $leaks,
            'n' => count($leaks),
            'worst' => $worst,
            'verdict' => $leaks === [] ? 'clean'
                : ($hasCritical ? 'spoils_the_vsl' : 'leaks_minor'),
        ];
    }

    /**
     * Build the spoiler catalog from the asset: product name, physical form, named ingredients,
     * price, scarcity numbers, guarantee, detailed-comparison markers. Everything here belongs to
     * the VSL's reveal, NOT the warmup bridge.
     *
     * @param  array<int,string>  $extraIngredients  explicit ingredient names if the asset parse misses any
     * @return array<int,array{term:string,category:string,severity:string,is_regex?:bool}>
     */
    public function catalogFromAsset(AiMarketingVslAsset $asset, array $extraIngredients = []): array
    {
        $cat = [];

        // Product + protocol name (CRITICAL — the single biggest spoiler)
        foreach ([$asset->mechanism_name, data_get($asset->offer, 'product_name')] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $cat[] = ['term' => $name, 'category' => 'product_name', 'severity' => 'critical'];
                // also the distinctive tail (e.g. "Drops Protocol", "Triple Hormone Drops")
                foreach ($this->distinctivePhrases($name) as $p) {
                    $cat[] = ['term' => $p, 'category' => 'product_name', 'severity' => 'critical'];
                }
            }
        }

        // Physical form (CRITICAL)
        foreach (self::FORM_LEXICON as $f) {
            $cat[] = ['term' => $f, 'category' => 'physical_form', 'severity' => 'critical'];
        }

        // Named ingredients (CRITICAL) — derived from the asset + explicit extras
        foreach ($this->ingredientNames($asset, $extraIngredients) as $ing) {
            $cat[] = ['term' => $ing, 'category' => 'named_ingredient', 'severity' => 'critical'];
        }

        // Price (HIGH) — but NOT the competitor's anchor price ("$1,000 a month" injection), which is
        // warmup-safe (the VSL itself uses it). The negative lookahead excludes month-anchored prices.
        // Proper number shape (\d{1,3}(,\d{3})*) + (?![,\d]) forbids stopping mid-number ("$1" out of
        // "$1,000"), so the month-anchor lookahead can't be tricked into clearing the enemy's
        // "$1,000-a-month" while still catching a real product price like "$49".
        $cat[] = ['term' => '/\$\s?\d{1,3}(?:,\d{3})*(?:\.\d{2})?(?![,\d])(?!\s?(?:[-\s]?a[-\s]?month|\/mo\b|\s?per\s?month|[-\s]?monthly))/i', 'category' => 'price', 'severity' => 'high', 'is_regex' => true];
        foreach (['per bottle', 'a bottle', '6-bottle', 'six-bottle', '6 bottles', 'bogo', 'buy 3 get', 'pay 3 get', 'pay 2 get', 'free shipping'] as $p) {
            $cat[] = ['term' => $p, 'category' => 'price', 'severity' => 'high'];
        }

        // Scarcity numbers (HIGH) — "84 bottles", "63 bottles left", "10 first buyers"
        $cat[] = ['term' => '/\b\d{1,4}\s+bottles?\b/i', 'category' => 'scarcity_number', 'severity' => 'high', 'is_regex' => true];
        $cat[] = ['term' => '/\b\d{1,3}\s+(?:left|remaining|in stock)\b/i', 'category' => 'scarcity_number', 'severity' => 'high', 'is_regex' => true];

        // Guarantee stamped as a feature (MEDIUM) — the number belongs to the close
        $days = (int) (data_get($asset->metrics, 'guarantee_days') ?? data_get($asset->offer, 'guarantee_days') ?? 0);
        if ($days > 0) {
            $cat[] = ['term' => $days.'-day money-back', 'category' => 'guarantee', 'severity' => 'medium'];
            $cat[] = ['term' => $days.'-day money back', 'category' => 'guarantee', 'severity' => 'medium'];
            $cat[] = ['term' => $days.' days', 'category' => 'guarantee', 'severity' => 'medium'];
        }
        $cat[] = ['term' => 'money-back guarantee', 'category' => 'guarantee', 'severity' => 'medium'];
        $cat[] = ['term' => 'money back guarantee', 'category' => 'guarantee', 'severity' => 'medium'];

        // Detailed comparison (HIGH)
        foreach (self::DETAILED_COMPARISON_MARKERS as $m) {
            $cat[] = ['term' => $m, 'category' => 'detailed_comparison', 'severity' => 'high'];
        }

        return $cat;
    }

    /**
     * @param  array<int,string>  $extra
     * @return array<int,string>
     */
    private function ingredientNames(AiMarketingVslAsset $asset, array $extra = []): array
    {
        $names = $extra;
        // Cross-language synonym GROUPS: if ANY synonym appears in the asset (often PT), add ALL of
        // them — so a PT asset ("berberina") still catches an EN bridge ("Berberine"). Missing this
        // let a real critical leak (Berberine) slip past on the delivered page.
        $groups = [
            ['Berberine', 'Berberina'],
            ['Resveratrol'],
            ['Quercetin', 'Quercetina'],
            ['Piperine', 'Piperina', 'Black pepper'],
            ['Curcumin', 'Cúrcuma', 'Turmeric'],
            ['Green tea extract', 'Green tea', 'Chá verde'],
        ];
        $blob = mb_strtolower(implode(' ', array_filter([
            (string) $asset->solution_mechanism,
            is_string($asset->offer) ? $asset->offer : json_encode($asset->offer, JSON_UNESCAPED_UNICODE),
        ])));
        foreach ($groups as $group) {
            foreach ($group as $syn) {
                if (str_contains($blob, mb_strtolower($syn))) {
                    foreach ($group as $all) {
                        $names[] = $all;
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Pull distinctive multi-word tails of a product name so "Triple Hormone Drops Protocol" also
     * catches "Triple Hormone Drops" and "Drops Protocol".
     *
     * @return array<int,string>
     */
    private function distinctivePhrases(string $name): array
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $out = [];
        $n = count($words);
        for ($len = max(2, $n - 1); $len >= 2 && $len < $n; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $out[] = implode(' ', array_slice($words, $i, $len));
            }
        }

        return array_values(array_unique($out));
    }

    /** First-occurrence char position → estimated second in the VSL. */
    private function revealSecond(string $transcript, string $term, float $charsPerSec): ?int
    {
        $pos = mb_stripos($transcript, $term);
        if ($pos === false) {
            return null;
        }

        return (int) round($pos / max(1.0, $charsPerSec));
    }

    private function mmss(int $s): string
    {
        return intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }

    private function fixFor(string $category): string
    {
        return match ($category) {
            'product_name' => 'NEVER name the product on a bridge — tease it ("the protocol", "this mixture"). The VSL names it.',
            'physical_form' => 'Remove the physical form (drops/capsule/etc). The reader must watch the VSL to learn HOW it is taken.',
            'named_ingredient' => 'Remove the ingredient name. Tease "4 natural ingredients" (count only) — the VSL names them.',
            'price' => 'No price on a bridge. The VSL builds value before the price reveal; showing it early collapses the value stack.',
            'scarcity_number' => 'Remove the stock count. Stock scarcity is a VSL/checkout close lever, not a bridge lever.',
            'guarantee' => 'Do not stamp the guarantee as a feature; at most tease "risk-free". The exact guarantee belongs to the close.',
            'detailed_comparison' => 'Cut the technical side-by-side. Tease the contrast ("without the needle") and let the VSL prove it.',
            default => 'Move this reveal into the VSL; keep the bridge to warmup + curiosity.',
        };
    }
}
