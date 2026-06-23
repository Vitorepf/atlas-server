<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * QualifiedKeywordPatternEngine — crystallizes the "memory-recall arbitrage" pattern: for a VSL offer,
 * the maximum-hit-rate Google Search traffic is POST-EXPOSURE RE-FINDERS — people the VSL already
 * warmed (saw the ad, or heard the slogan/celebrity/mechanism word-of-mouth) who now Google the thing
 * they remember. The product NAME is forbidden (affiliates can't bid the merchant brand, and an unknown
 * product has no demand); the gold is the coined MECHANISM/TRICK, the planted SLOGAN, the CELEBRITY +
 * claim, the vivid POWER PHRASES, and the trust-check OBJECTION terms — each an "owned root" that
 * proves exposure. The keep/kill law: a keyword qualifies IFF, stripped of its commercial modifier, the
 * remaining ROOT is a phrase this VSL coined/owns (or an anchored category-alternative). Deterministic,
 * provider-free. This is the literal "gelatine trick" pattern, generalized.
 */
class QualifiedKeywordPatternEngine
{
    /** Intent modifiers per family (the commercial tail; the root is what qualifies). */
    private const MOD_MECHANISM = ['', 'protocol', 'drops', 'recipe', 'reviews', 'does it work', 'where to buy'];

    private const MOD_SLOGAN = ['', 'drops', 'protocol', 'reviews', 'weight loss', 'where to buy'];

    private const MOD_CELEBRITY = ['weight loss protocol', 'weight loss drops', 'diet protocol', 'at home protocol', 'hormone drops', 'protocol'];

    private const MOD_OBJECTION = ['reviews', 'does it work', 'real or fake', 'legit', 'scam', 'cost', 'where to buy'];

    /** Generic head terms that are NEVER bought bare (no owned root → unqualified scatter). */
    private const GENERIC_STOP = ['weight loss', 'diet', 'lose weight', 'fat loss', 'slim', 'belly fat', 'how to lose weight', 'weight loss tips', 'best diet'];

    /** Bare Rx/trademark drug names — forbidden as a bid token unless anchored to the offer's device. */
    private const DRUGS = ['retatrutide', 'ozempic', 'wegovy', 'mounjaro', 'zepbound', 'semaglutide', 'tirzepatide', 'glp-1', 'glp 1'];

    /**
     * @param  array<string,mixed>  $opts  slogan, niche, celebrity (overrides), product_name (override)
     * @return array{artifacts:array<string,mixed>,tiers:array<int,array<string,mixed>>,negatives:array<int,string>,flat:array<int,string>,pattern:array<int,string>}
     */
    public function build(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $art = $this->harvest($asset, $opts);

        $tiers = [];

        // Tier 1 — MECHANISM / TRICK (coined device): hyper, exact/phrase. Expand each coined name into
        // the full phrase + the phrase minus its trailing generic device-suffix (so "triple hormone
        // drops protocol" also yields "triple hormone drops") — the variants a re-finder actually types.
        $roots1 = $this->expandRoots(array_filter([$art['mechanism'], $art['trick']]));
        $tiers[] = $this->tier('mechanism_trick', 'hyper', 'exact/phrase', $roots1, self::MOD_MECHANISM,
            'Coined device name — typing it proves VSL exposure; ~zero generic supply. The "gelatine trick" tier: first money on the account.');

        // Tier 2 — SLOGAN / planted meme: hyper.
        if ($art['slogan'] !== '') {
            $tiers[] = $this->tier('slogan', 'hyper', 'phrase', [$art['slogan']], self::MOD_SLOGAN,
                'Coined campaign slogan — pure ad-recall + word-of-mouth. Captures viewers AND people who only heard the phrase.');
        }

        // Tier 3 — CELEBRITY + modifier (the scale tier): high. NEVER bare (bare = gossip, unqualified).
        if ($art['celebrities'] !== []) {
            $celRoots = $this->celebrityRoots($art['celebrities']);
            $tiers[] = $this->tierCompound('celebrity', 'high', 'phrase', $celRoots, self::MOD_CELEBRITY,
                'Celebrity + niche modifier — highest QUALIFIED volume. Bare name drifts to gossip, so it is ALWAYS compounded with a weight-loss/protocol modifier.');
        }

        // Tier 4 — POWER PHRASES (vivid benefit lines she half-remembers): high.
        if ($art['power_phrases'] !== []) {
            $tiers[] = $this->tier('power_phrase', 'high', 'phrase', $art['power_phrases'], ['', 'drops', 'protocol'],
                'Memorized benefit lines (numbers + named mechanism) — quasi-owned fingerprints of THIS VSL.');
        }

        // Tier 5 — CATEGORY-ALTERNATIVE (in-market, may not have seen the VSL): medium, scalable.
        if ($art['category_anchors'] !== []) {
            $tiers[] = $this->tier('category_alternative', 'medium', 'phrase', $art['category_anchors'],
                ['alternative', 'natural alternative', 'without injection', 'natural drops', 'over the counter'],
                'In-market category solution-seekers (e.g. "retatrutide/ozempic alternative natural") — ready to buy the category, broader reach, lighter qualification.');
        }

        // Tier 6 — OBJECTION / VERIFICATION (trust-check before re-buy): high. Owned root + verify tail.
        $objRoots = array_values(array_unique(array_filter([$art['mechanism'], $art['trick'], $art['slogan']])));
        if ($objRoots !== []) {
            $tiers[] = $this->tier('objection_verification', 'high', 'phrase', $objRoots, self::MOD_OBJECTION,
                'The buyer doing a final trust-check ("does X work", "X reviews", "X legit") — owned root keeps it pre-sold, not generic skepticism.');
        }

        // Apply the keep/kill law to every keyword and collect the flat list.
        $flat = [];
        foreach ($tiers as &$t) {
            $t['keywords'] = array_values(array_filter($t['keywords'], fn ($k) => $this->keep($k, $art)));
            $flat = array_merge($flat, $t['keywords']);
        }
        unset($t);
        $tiers = array_values(array_filter($tiers, fn ($t) => $t['keywords'] !== []));

        return [
            'artifacts' => $art,
            'tiers' => $tiers,
            'negatives' => $this->negatives($art),
            'flat' => array_values(array_unique($flat)),
            'pattern' => $this->patternRules(),
        ];
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function harvest(AiMarketingVslAsset $asset, array $opts): array
    {
        $mechanism = strtolower(trim((string) ($asset->mechanism_name ?? '')));
        $trick = strtolower(trim((string) ($asset->trick ?? '')));
        $product = strtolower(trim((string) ($opts['product_name'] ?? data_get($asset->offer, 'product_name') ?? '')));
        $niche = strtolower(trim((string) ($opts['niche'] ?? $asset->niche ?? 'weight loss')));

        $power = array_map('strval', (array) ($asset->power_phrases ?? []));
        $slogan = strtolower(trim((string) ($opts['slogan'] ?? $this->detectSlogan($power))));

        $celebrities = $opts['celebrity'] ?? $this->detectCelebrities($asset);

        // Vivid, ownable benefit phrases: multi-word, has a number or a named-mechanism token, not the slogan/product.
        $blob = strtolower(implode(' | ', $power).' '.(string) $asset->angle.' '.(string) $asset->hook);
        $powerPhrases = $this->detectPowerPhrases($power, $slogan, $product);

        // Category anchors: competitor drugs actually named in the offer → category-alternative roots.
        $category = [];
        foreach (self::DRUGS as $d) {
            if ($d !== 'glp-1' && $d !== 'glp 1' && str_contains($blob, $d)) {
                $category[] = $d;
            }
        }
        if (str_contains($blob, 'glp-1') || str_contains($blob, 'glp 1')) {
            $category[] = 'glp-1';
        }

        return [
            'mechanism' => $mechanism, 'trick' => $trick, 'slogan' => $slogan,
            'celebrities' => array_values(array_unique($celebrities)),
            'power_phrases' => $powerPhrases, 'category_anchors' => array_values(array_unique($category)),
            'product_name' => $product, 'niche' => $niche,
        ];
    }

    /** @param array<int,string> $power */
    private function detectSlogan(array $power): string
    {
        foreach ($power as $p) {
            $words = preg_split('/\s+/', trim($p)) ?: [];
            // A coined slogan = 3-5 words, Title Case, not a generic benefit line.
            $titleCase = count(array_filter($words, fn ($w) => $w !== '' && ctype_upper($w[0]))) >= 3;
            if (count($words) >= 3 && count($words) <= 5 && $titleCase
                && ! preg_match('/\d/', $p) && ! str_contains(strtolower($p), 'ingredient') && ! str_contains(strtolower($p), 'hormone')) {
                return $p;
            }
        }

        return '';
    }

    /** @return array<int,string> */
    private function detectCelebrities(AiMarketingVslAsset $asset): array
    {
        $out = [];
        // Primary endorser from the authority device, then person entities.
        foreach ((array) data_get($asset->persuasion_devices, 'authority', []) as $name) {
            $name = trim((string) $name);
            // Only well-known public figures (2-word proper names), not institutions.
            if (preg_match('/^[A-Z][a-z]+ [A-Z]/', $name) && ! preg_match('/University|Journal|FDA|Institute|News|Department|Centers/i', $name)) {
                $out[] = strtolower($name);
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * @param  array<int,string>  $power
     * @return array<int,string>
     */
    private function detectPowerPhrases(array $power, string $slogan, string $product): array
    {
        $out = [];
        foreach ($power as $p) {
            $pl = strtolower(trim($p));
            $words = count(preg_split('/\s+/', $pl) ?: []);
            if ($words < 3 || $pl === $slogan || ($product !== '' && str_contains($pl, $product))) {
                continue;
            }
            // Ownable benefit = mentions the offer's specific mechanism markers.
            if (preg_match('/\b(four|three|3|4|glp|gip|glucagon|hormone|ingredient|injection|24 hours|fat burning|fat-burning)\b/', $pl)) {
                $out[] = $pl;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 8);
    }

    /**
     * @param  array<int,string>  $celebrities
     * @return array<int,string>
     */
    private function celebrityRoots(array $celebrities): array
    {
        // The endorser's surname/full name is the searchable root (compounded with a modifier downstream).
        return array_slice($celebrities, 0, 2);
    }

    /**
     * @param  array<int,string>  $roots
     * @param  array<int,string>  $mods
     * @return array<string,mixed>
     */
    private function tier(string $key, string $qual, string $match, array $roots, array $mods, string $why): array
    {
        $kw = [];
        foreach ($roots as $r) {
            $r = trim($r);
            if ($r === '') {
                continue;
            }
            foreach ($mods as $m) {
                // Skip a modifier whose word is already in the root ("…drops protocol" + "protocol").
                if ($m !== '' && $this->rootHasWord($r, $m)) {
                    continue;
                }
                $kw[] = trim($r.' '.$m);
            }
        }

        return ['family' => $key, 'qualification' => $qual, 'match_type' => $match, 'why' => $why,
            'roots' => $roots, 'keywords' => array_values(array_unique($kw))];
    }

    /** True if any word of the (possibly multi-word) modifier already appears in the root. */
    private function rootHasWord(string $root, string $mod): bool
    {
        $root = ' '.strtolower($root).' ';
        foreach (preg_split('/\s+/', strtolower($mod)) ?: [] as $w) {
            if ($w !== '' && str_contains($root, ' '.$w.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand each coined name into the full phrase + the phrase minus a single trailing generic
     * device-suffix word (protocol/method/drops/…), giving the shorter variant re-finders also type.
     *
     * @param  array<int,string>  $names
     * @return array<int,string>
     */
    private function expandRoots(array $names): array
    {
        $suffixes = ['protocol', 'method', 'system', 'formula', 'ritual', 'routine', 'hack', 'recipe', 'solution', 'program'];
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $out[] = $name;
            $words = preg_split('/\s+/', $name) ?: [];
            if (count($words) >= 3 && in_array(strtolower(end($words)), $suffixes, true)) {
                $out[] = trim(implode(' ', array_slice($words, 0, -1)));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Celebrity tier: ALWAYS compound (root + modifier), never the bare name.
     *
     * @param  array<int,string>  $roots
     * @param  array<int,string>  $mods
     * @return array<string,mixed>
     */
    private function tierCompound(string $key, string $qual, string $match, array $roots, array $mods, string $why): array
    {
        $kw = [];
        foreach ($roots as $r) {
            foreach ($mods as $m) {
                $kw[] = trim($r.' '.$m);
            }
        }

        return ['family' => $key, 'qualification' => $qual, 'match_type' => $match, 'why' => $why,
            'roots' => $roots, 'keywords' => array_values(array_unique($kw))];
    }

    /**
     * THE KEEP/KILL LAW: keep IFF the keyword carries an owned root and is not a forbidden type.
     *
     * @param  array<string,mixed>  $art
     */
    private function keep(string $kw, array $art): bool
    {
        $k = strtolower(trim($kw));
        if ($k === '') {
            return false;
        }
        // KILL — product name (forbidden).
        if ($art['product_name'] !== '' && str_contains($k, $art['product_name'])) {
            return false;
        }
        // KILL — exact generic head term (no owned root).
        if (in_array($k, self::GENERIC_STOP, true)) {
            return false;
        }
        // KILL — bare Rx/drug name (only allowed when anchored to the device wording).
        foreach (self::DRUGS as $d) {
            if ($k === $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $art
     * @return array<int,string>
     */
    private function negatives(array $art): array
    {
        $neg = ['free', 'cheap', 'recipe', 'recipes', 'diy', 'homemade', 'pdf', 'download', 'torrent', 'reddit',
            'coupon', 'job', 'jobs', 'salary', 'sample', 'side effects', 'is it safe', 'what is', 'meaning', 'meme',
            'shirt', 'hat', 'news', 'photos', 'net worth'];
        if ($art['product_name'] !== '') {
            $neg[] = $art['product_name'];
        }
        foreach (self::GENERIC_STOP as $g) {
            $neg[] = $g;
        }

        return array_values(array_unique($neg));
    }

    /** @return array<int,string> */
    private function patternRules(): array
    {
        return [
            'LAW: max-hit-rate Search traffic = post-exposure re-finders; keyword = {owned_root} × {intent_modifier}.',
            'STEP 1 harvest 6 artifacts from the VSL/offer (mechanism_name, trick, slogan, celebrity, power phrases, competitor category) — never from a blind keyword tool.',
            'STEP 2 rank by exposure-exclusivity: the harder it is to type the root WITHOUT seeing the ad, the higher the qualification (mechanism/slogan = hyper; celebrity = high-volume; category = medium).',
            'STEP 3 KEEP/KILL: keep IFF, stripped of the modifier, the root is a phrase the VSL coined/owns OR an anchored category-alternative. KILL product name, bare generic, bare drug, free/recipe.',
            'STEP 4 structure: one tight ad group per family; exact/phrase on owned roots; route each keyword to a congruent advertorial whose H1 mirrors the searched root (keyword = ad headline = page H1).',
            'ANTI-GOODHART: optimize profit-per-click via real buy-intent, never cheap clicks or CTR; a high-volume bare term that fails the exposure test is a crime.',
        ];
    }
}
