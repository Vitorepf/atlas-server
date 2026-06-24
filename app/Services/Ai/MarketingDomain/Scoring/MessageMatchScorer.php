<?php

namespace App\Services\Ai\MarketingDomain\Scoring;

use App\Models\AiMarketingVslAsset;

/**
 * MessageMatchScorer — the #1 page-conversion lever made executable. Scores ad → page → VSL
 * congruency on three dimensions (message_match = H1 echoes the ad headline; information_scent =
 * page carries the VSL promise keywords; tone = ad echoes the VSL language) via deterministic token
 * Jaccard over a stopword-filtered lexicon. Congruency can ~double EPC, so the gap (delta) is a
 * high-leverage, directly-actionable number. No external libs, no DB.
 */
class MessageMatchScorer
{
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'your', 'you', 'is', 'are',
        'this', 'that', 'how', 'why', 'what', 'best', 'get', 'now', 'new', 'o', 'a', 'os', 'as', 'de', 'da',
        'do', 'e', 'que', 'para', 'com', 'seu', 'sua', 'um', 'uma', 'como',
    ];

    /**
     * @return array<string,mixed>
     */
    public function score(string $adHeadline, string $pageH1, string $vslPromise): array
    {
        $ad = $this->tokens($adHeadline);
        $h1 = $this->tokens($pageH1);
        $vsl = $this->tokens($vslPromise);

        $messageMatch = $this->jaccard($ad, $h1);
        $infoScent = $this->jaccard($h1, $vsl);
        $tone = $this->jaccard($ad, $vsl);

        $score = (int) round(($messageMatch * 0.5 + $infoScent * 0.3 + $tone * 0.2) * 100);

        return [
            'score' => $score,
            'delta' => 100 - $score,
            'dimension_scores' => [
                'message_match' => (int) round($messageMatch * 100),
                'information_scent' => (int) round($infoScent * 100),
                'tone' => (int) round($tone * 100),
            ],
            'missing_keywords' => array_values(array_diff($ad, $h1)),
            'weakest_dimension' => $this->weakest($messageMatch, $infoScent, $tone),
            'note' => $score >= 70
                ? 'Congruência forte — a página cumpre a promessa do anúncio.'
                : 'Congruência fraca — o H1 não espelha o anúncio/VSL; alinhar pode ~dobrar o EPC.',
        ];
    }

    /**
     * Funnel-hop congruence via OVERLAP COEFFICIENT (|A∩B| / min(|A|,|B|)), not Jaccard. For an
     * ad→page hop the page is always far longer than the ad, which collapses Jaccard (it divides by the
     * union) even when the page fully echoes the ad's promise. Overlap coefficient asks the real scent
     * question — "does the shorter stage's vocabulary survive into the other stage?" — independent of
     * length asymmetry. Returns 0-100.
     */
    public function congruence(string $a, string $b): int
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return 0;
        }
        $inter = count(array_intersect($ta, $tb));
        $min = min(count($ta), count($tb));

        return $min > 0 ? (int) round($inter / $min * 100) : 0;
    }

    /**
     * Classify the mismatch and recommend H1 angles from the extracted VSL fields.
     *
     * @return array<string,mixed>
     */
    public function diagnoseHeadlineMismatch(string $adHeadline, string $currentH1, AiMarketingVslAsset $vsl): array
    {
        $promise = trim((string) $vsl->core_promise) ?: trim((string) $vsl->big_idea);
        $base = $this->score($adHeadline, $currentH1, $promise);

        $angles = array_values(array_filter([
            $promise !== '' ? "promessa: {$promise}" : null,
            trim((string) $vsl->mechanism_name) !== '' ? 'mecanismo: '.trim((string) $vsl->mechanism_name) : null,
            trim((string) $vsl->solution_mechanism) !== '' ? 'mecanismo da solução: '.trim((string) $vsl->solution_mechanism) : null,
            trim((string) $vsl->problem_mechanism) !== '' ? 'problema: '.trim((string) $vsl->problem_mechanism) : null,
            ...array_map(static fn ($p): string => 'power: '.(string) $p, array_slice((array) $vsl->power_phrases, 0, 2)),
        ]));

        $mismatchType = match ($base['weakest_dimension']) {
            'message_match' => 'emotion',     // H1 doesn't echo the ad's emotional hook
            'information_scent' => 'mechanism', // page lacks the VSL's mechanism/promise keywords
            default => 'claim',
        };

        return array_merge($base, [
            'mismatch_type' => $mismatchType,
            'recommended_h1_angles' => $angles,
        ]);
    }

    private function weakest(float $mm, float $is, float $tone): string
    {
        $map = ['message_match' => $mm, 'information_scent' => $is, 'tone' => $tone];
        asort($map);

        return (string) array_key_first($map);
    }

    /**
     * @param  array<int,string>  $a
     * @param  array<int,string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $s): array
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9á-ú\s]/u', ' ', $s) ?? $s;
        $words = preg_split('/\s+/', trim($s)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w): bool => $w !== '' && strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true),
        )));
    }
}
