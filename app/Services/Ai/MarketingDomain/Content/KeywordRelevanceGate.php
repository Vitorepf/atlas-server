<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;

/**
 * KeywordRelevanceGate — the "no crime" gate. A bridge page exists to warm a lead for ONE specific
 * VSL. If a PROMINENT thematic keyword on the bridge (headline / kicker / subheadline / section
 * heading) has nothing to do with the VSL, that is a critical error: the visitor was promised
 * something the VSL never delivers → broken scent, wasted click, policy risk.
 *
 * The gate is CALIBRATED to flag the real crime and not block on noise:
 *   - CRIME (fail-closed): a prominent term that is NOT anchored in the VSL/own-pattern AND is a
 *     thematic niche term — proven by matching another niche's converting keywords (cross-niche
 *     contamination) or by being a known diet/protocol/ingredient name. e.g. a "keto" headline on a
 *     GLP-1 drops VSL.
 *   - LEAK (warning, does not block): a meta/process word that bled from the build instructions into
 *     the copy ("bridge", "fold", "teaser", "format") — signals a dirty generation to clean up.
 *   - Generic/uncommon copy words are never crimes.
 *
 * Anchor sources: (1) the VSL itself (transcript + dissected fields), (2) the winning pattern's
 * converting keywords (a legitimate search-term→angle bridge). Deterministic.
 */
class KeywordRelevanceGate
{
    /** Diet/protocol/ingredient names that are strongly thematic — a crime if unanchored. */
    private const NICHE_TERMS = [
        'keto', 'ketogenic', 'paleo', 'carnivore', 'vegan', 'atkins', 'mediterranean', 'whole30',
        'jello', 'gelatin', 'gelatine', 'ozempic', 'wegovy', 'mounjaro', 'semaglutide', 'retatrutide',
        'tirzepatide', 'berberine', 'turmeric', 'curcumin', 'resveratrol', 'quercetin', 'glp', 'peptide',
        'peptides', 'collagen', 'prostate', 'tinnitus', 'neuropathy', 'vertigo', 'parasite', 'parasites',
        'diabetes', 'diabetic', 'thyroid', 'cortisol', 'insulin', 'metabolism', 'fasting', 'bariatric',
        'liposuction', 'botox', 'ozempicface', 'keto-diet', 'apple-cider', 'vinegar', 'magnesium',
    ];

    /** Unambiguous build-process words that should never appear in the copy (real English words like
     *  "format"/"section" are handled by CopyQualityGate, not here, to avoid false positives). */
    private const META_LEAK = [
        'teaser', 'fold', 'folds', 'advertorial', 'why-now', 'open-loop', 'open-loops', 'kicker',
        'blueprint', 'placeholder', 'lorem', 'ipsum', 'body-copy', 'sub-headline',
    ];

    /** Generic advertorial/persuasion vocabulary — free to use, never a thematic-keyword crime. */
    private const COPY_WORDS = [
        'discover', 'secret', 'simple', 'easy', 'new', 'real', 'truth', 'shocking', 'weird', 'strange',
        'breakthrough', 'finally', 'now', 'today', 'free', 'why', 'how', 'what', 'everyone', 'nobody',
        'before', 'after', 'results', 'result', 'proven', 'science', 'scientist', 'scientists', 'study',
        'studies', 'report', 'special', 'exclusive', 'warning', 'alert', 'revealed', 'reveals', 'reveal',
        'story', 'behind', 'about', 'this', 'that', 'these', 'those', 'here', 'there', 'thing', 'things',
        'people', 'person', 'everything', 'anything', 'something', 'really', 'actually', 'just', 'more',
        'most', 'less', 'best', 'better', 'good', 'great', 'amazing', 'incredible', 'unbelievable',
        'every', 'each', 'all', 'one', 'two', 'three', 'first', 'last', 'next', 'only', 'even', 'still',
        'never', 'always', 'ever', 'when', 'where', 'who', 'which', 'into', 'over', 'under', 'with',
        'without', 'your', 'yours', 'they', 'them', 'their', 'her', 'his', 'she', 'him', 'man', 'woman',
        'men', 'women', 'american', 'americans', 'years', 'year', 'old', 'age', 'day', 'days', 'week',
        'weeks', 'month', 'months', 'time', 'times', 'way', 'ways', 'said', 'says', 'talking', 'searching',
        'different', 'usual', 'common', 'normal', 'past', 'plans', 'plan', 'fixes', 'fix', 'failing',
        'fail', 'failed', 'explanation', 'explain', 'laid', 'reason', 'reasons', 'authority', 'trust',
        'official', 'announcement', 'update', 'breaking', 'news', 'inside', 'revealed', 'found', 'find',
        'change', 'changed', 'changing', 'help', 'helps', 'helped', 'using', 'used', 'makes', 'making',
        'getting', 'going', 'doctors', 'doctor', 'experts', 'expert', 'womens', 'mens',
    ];

    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'are', 'was', 'were',
        'it', 'as', 'at', 'be', 'by', 'but', 'not', 'no', 'so', 'do', 'does', 'did', 'have', 'has', 'had',
        'will', 'would', 'can', 'could', 'its', 'our', 'o', 'os', 'as', 'de', 'da', 'do', 'que', 'para',
        'com', 'um', 'uma', 'na', 'no', 'se', 'e', 'may', 'might', 'must', 'from', 'been', 'being',
    ];

    /**
     * @param  array<string,mixed>  $bridge
     * @param  array<int,string>  $otherNicheKeywords  converting keywords of OTHER niches (cross-niche crime signal)
     * @return array<string,mixed>
     */
    public function evaluate(array $bridge, AiMarketingVslAsset $asset, ?AiMarketingWinningPattern $pattern = null, array $otherNicheKeywords = []): array
    {
        $anchorTokens = $this->buildAnchorTokens($asset, $pattern);
        $patternPhrases = $this->patternPhrases($pattern);
        $otherNiche = $this->phraseTokenSet($otherNicheKeywords);

        $prominent = $this->prominentTerms($bridge);
        $anchored = [];
        $orphans = [];   // crimes
        $leaks = [];
        foreach ($prominent as $term => $where) {
            if (! $this->isThematic($term)) {
                continue;
            }
            if ($this->isAnchored($term, $anchorTokens, $patternPhrases)) {
                $anchored[$term] = $where;

                continue;
            }
            if (in_array($term, self::META_LEAK, true)) {
                $leaks[] = $term;

                continue;
            }
            // Unanchored: a crime ONLY if it is a real niche term (known name or another niche's keyword).
            if ($this->isNicheTerm($term, $otherNiche)) {
                $orphans[] = ['keyword' => $term, 'where' => array_values(array_unique($where))];
            }
        }

        $thematicTotal = count($anchored) + count($orphans);
        $anchorRate = $thematicTotal > 0 ? (int) round(count($anchored) / $thematicTotal * 100) : 100;
        $verdict = $orphans !== [] ? 'critical' : ($leaks !== [] ? 'warn' : 'ok');

        return [
            'verdict' => $verdict,
            'safe_to_publish' => $orphans === [],
            'anchor_rate' => $anchorRate,
            'anchored_keywords' => array_keys($anchored),
            'orphan_keywords' => $orphans,
            'meta_leaks' => array_values(array_unique($leaks)),
            'thematic_terms_checked' => $thematicTotal,
            'note' => $orphans !== []
                ? 'CRIME: '.count($orphans).' keyword(s) de nicho sem ancoragem na VSL nem nos padrões — a bridge promete um tema que a VSL não entrega.'
                : ($leaks !== []
                    ? 'Limpo de crime, mas vazaram termos meta do guia na copy ('.implode(', ', array_unique($leaks)).') — regenerar para limpar.'
                    : 'Toda keyword temática proeminente está ancorada na VSL ou nos padrões que vendem — sem crime.'),
        ];
    }

    private function isNicheTerm(string $term, array $otherNicheTokens): bool
    {
        $stem = $this->stem($term);
        if (in_array($term, self::NICHE_TERMS, true) || in_array($stem, self::NICHE_TERMS, true)) {
            return true;
        }

        return isset($otherNicheTokens[$term]) || isset($otherNicheTokens[$stem]);
    }

    /**
     * @return array<string,true>
     */
    private function buildAnchorTokens(AiMarketingVslAsset $asset, ?AiMarketingWinningPattern $pattern): array
    {
        $blobs = [
            (string) $asset->transcript, (string) $asset->big_idea, (string) $asset->core_promise,
            (string) $asset->mechanism_name, (string) $asset->problem_mechanism, (string) $asset->solution_mechanism,
            (string) $asset->angle, (string) $asset->trick, (string) $asset->hook, (string) $asset->essential_summary,
            (string) $asset->niche, (string) $asset->sub_niche,
        ];
        foreach (['avatar', 'offer', 'metrics', 'persuasion', 'claims', 'entities', 'top_terms', 'keywords', 'lead', 'persuasion_devices', 'power_phrases'] as $field) {
            $val = $asset->{$field};
            if (is_array($val)) {
                array_walk_recursive($val, static function ($v) use (&$blobs): void {
                    if (is_string($v)) {
                        $blobs[] = $v;
                    }
                });
            }
        }
        foreach ($this->patternPhrases($pattern) as $phrase) {
            $blobs[] = $phrase;
        }

        $set = [];
        foreach ($blobs as $blob) {
            foreach ($this->tokenize($blob) as $tok) {
                $set[$tok] = true;
                $set[$this->stem($tok)] = true;
            }
        }

        return $set;
    }

    /**
     * @return array<int,string>
     */
    private function patternPhrases(?AiMarketingWinningPattern $pattern): array
    {
        if ($pattern === null || ! is_array($pattern->converting_keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($k): string => strtolower(trim((string) (is_array($k) ? ($k['term'] ?? '') : $k))),
            $pattern->converting_keywords,
        )));
    }

    /**
     * @param  array<int,string>  $phrases
     * @return array<string,true>
     */
    private function phraseTokenSet(array $phrases): array
    {
        $set = [];
        foreach ($phrases as $phrase) {
            foreach ($this->tokenize((string) $phrase) as $tok) {
                $set[$tok] = true;
                $set[$this->stem($tok)] = true;
            }
        }

        return $set;
    }

    /**
     * @param  array<string,mixed>  $bridge
     * @return array<string,array<int,string>>
     */
    private function prominentTerms(array $bridge): array
    {
        $surfaces = [
            'headline' => (string) ($bridge['headline'] ?? ''),
            'kicker' => (string) ($bridge['kicker'] ?? ''),
            'subheadline' => (string) ($bridge['subheadline'] ?? ''),
            'mechanism_tease' => (string) ($bridge['mechanism_tease'] ?? ''),
        ];
        $sections = is_array($bridge['body_sections'] ?? null) ? $bridge['body_sections'] : [];
        foreach ($sections as $i => $s) {
            if (is_array($s)) {
                $surfaces['section_'.($i + 1).'_heading'] = (string) ($s['heading'] ?? $s['title'] ?? '');
            }
        }

        $terms = [];
        foreach ($surfaces as $where => $text) {
            foreach ($this->tokenize($text) as $tok) {
                $terms[$tok][] = $where;
            }
        }

        return $terms;
    }

    private function isThematic(string $term): bool
    {
        if (mb_strlen($term) < 4 || is_numeric($term)) {
            return false;
        }

        return ! in_array($term, self::STOPWORDS, true)
            && ! in_array($term, self::COPY_WORDS, true)
            && ! in_array($this->stem($term), self::COPY_WORDS, true);
    }

    /**
     * @param  array<string,true>  $anchorTokens
     * @param  array<int,string>  $patternPhrases
     */
    private function isAnchored(string $term, array $anchorTokens, array $patternPhrases): bool
    {
        $stem = $this->stem($term);
        if (isset($anchorTokens[$term]) || isset($anchorTokens[$stem])) {
            return true;
        }
        foreach ($patternPhrases as $phrase) {
            if ($phrase !== '' && (str_contains($phrase, $term) || str_contains($phrase, $stem))) {
                return true;
            }
        }

        return false;
    }

    private function stem(string $w): string
    {
        foreach (['ing', 'ies', 'es', 's', 'ed'] as $suffix) {
            if (mb_strlen($w) > mb_strlen($suffix) + 2 && str_ends_with($w, $suffix)) {
                return $suffix === 'ies' ? mb_substr($w, 0, -3).'y' : mb_substr($w, 0, -mb_strlen($suffix));
            }
        }

        return $w;
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $s) ?? $s;
        $parts = preg_split('/\s+/u', trim($s)) ?: [];

        return array_values(array_filter($parts, static fn (string $w): bool => $w !== '' && mb_strlen($w) > 2));
    }
}
