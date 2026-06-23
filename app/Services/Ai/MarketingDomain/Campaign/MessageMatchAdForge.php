<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * MessageMatchAdForge — the Quality-Score engineering layer. Quality Score = expected CTR + ad
 * relevance + landing-page experience, and MESSAGE MATCH (keyword text → ad headline → advertorial H1
 * echoing each other) is the single mechanic that lifts all three at once. This forge takes an owned-
 * root keyword family (from QualifiedKeywordPatternEngine) + the advertorial H1 and deterministically
 * generates a policy-aware RSA (≤15 headlines/30 chars, ≤4 descriptions/90 chars) that maximizes the
 * keyword↔headline↔H1 overlap, enforces Google's RSA mechanics (asset counts, uniqueness, keyword
 * coverage, CTA presence), and screens a weight-loss-sensitive compliance lexicon. Pure PHP, no LLM —
 * the owned root IS the message-match, so templating beats generation here.
 */
class MessageMatchAdForge
{
    private const HL_MAX = 30;

    private const DESC_MAX = 90;

    /** Action verbs (Google's set) — at least one headline/description must carry one (expected CTR). */
    private const CTA_VERBS = ['Watch', 'See', 'Get', 'Discover', 'Find', 'Try'];

    /** Weight-loss sensitive-category compliance blocklist (body-shaming + unrealistic-result + Rx).
     * NOTE: bare "fat" is core vocabulary ("fat burning"), NOT body-shaming — only flag genuine risks. */
    private const COMPLIANCE_BLOCK = ['guaranteed', 'cure', 'lose 10 lbs', 'overnight', 'miracle', 'obese', 'ugly', 'before you die', 'flat belly fast'];

    private const STOP = ['the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'with', 'in', 'on', 'is', 'it', 'your'];

    /**
     * @param  array{family?:string,root?:string,keyword?:string}  $adGroup
     * @param  array<string,mixed>  $opts  h1, benefits[], compliance_required(bool)
     * @return array<string,mixed>
     */
    public function forge(array $adGroup, array $opts = []): array
    {
        $root = trim((string) ($adGroup['root'] ?? $adGroup['keyword'] ?? ''));
        $family = (string) ($adGroup['family'] ?? 'mechanism_trick');
        $h1 = trim((string) ($opts['h1'] ?? ''));
        $benefits = array_map('strval', (array) ($opts['benefits'] ?? ['No injection no needle', '4 natural ingredients', 'Used by women over 40', 'The at-home method', 'As seen on the news']));

        $rootTitle = $this->title($root);

        // Headline candidates — ordered so keyword-coverage headlines come first.
        $candidates = array_merge(
            $this->rootHeadlines($rootTitle),
            $this->ctaHeadlines(),
            array_map(fn ($b) => $this->title($b), $benefits),
            ['As Seen On The News', 'The Method Women Use', 'Before It Is Taken Down'],
        );

        $headlines = [];
        foreach ($candidates as $c) {
            $c = $this->fit($c, self::HL_MAX);
            if ($c === '' || $this->blocked($c) || $this->dupes($c, $headlines)) {
                continue;
            }
            $headlines[] = $c;
            if (count($headlines) >= 15) {
                break;
            }
        }

        // Descriptions (≤90), message-matched + CTA + compliance-safe.
        $descPool = [
            'See the at-home method women over 40 are talking about. Watch the free presentation now.',
            'As discussed on national news. Watch the short briefing before it is taken offline today.',
            'The reason it was never your willpower — explained in the free video. Watch it to the end.',
            'No injection, no prescription. See how the protocol works in the free presentation.',
        ];
        $descriptions = [];
        foreach ($descPool as $d) {
            $d = $this->fit($d, self::DESC_MAX);
            if ($d !== '' && ! $this->blocked($d) && ! $this->dupes($d, $descriptions)) {
                $descriptions[] = $d;
            }
        }

        // Message-match coverage: keyword ↔ primary headline ↔ advertorial H1.
        $kwToks = $this->tokens($root);
        $coverHeadline = $this->coverageCount($root, $headlines);
        $mmHeadline = $headlines !== [] ? $this->recall($kwToks, $this->tokens($headlines[0])) : 0.0;
        $mmH1 = $h1 !== '' ? $this->recall($kwToks, $this->tokens($h1)) : null;
        $messageMatch = round((($mmHeadline) + ($mmH1 ?? $mmHeadline)) / 2, 2);

        $hasCta = $this->hasCta($headlines) || $this->hasCta($descriptions);
        $uniqueness = $this->uniqueness($headlines);
        $strength = $this->adStrength(count($headlines), count($descriptions), $uniqueness, $coverHeadline, $hasCta);

        $warnings = [];
        if (count($headlines) < 12) {
            $warnings[] = 'menos de 12 headlines ('.count($headlines).') — Ad Strength sobe com mais variedade';
        }
        if ($coverHeadline < 3) {
            $warnings[] = 'menos de 3 headlines contêm a keyword ('.$coverHeadline.') — ad relevance cai';
        }
        if (! $hasCta) {
            $warnings[] = 'nenhum CTA verb — expected CTR cai';
        }
        if ($mmH1 !== null && $mmH1 < 0.3) {
            $warnings[] = 'H1 da advertorial não casa com a keyword ('.$mmH1.') — landing page experience cai';
        }

        return [
            'family' => $family,
            'keyword' => $root,
            'headlines' => $headlines,
            'descriptions' => $descriptions,
            'message_match' => $messageMatch,
            'keyword_in_headline_count' => $coverHeadline,
            'h1_match' => $mmH1,
            'has_cta' => $hasCta,
            'uniqueness' => round($uniqueness, 2),
            'ad_strength' => $strength,
            'pinning' => ! empty($opts['compliance_required']) ? 'permitido (compliance lock)' : 'evitar (custa Ad Strength + CTR)',
            'warnings' => $warnings,
            'qs_levers' => [
                'expected_ctr' => 'owned-root keyword + CTA verb + keyword-in-headline',
                'ad_relevance' => 'keyword ↔ headline overlap (coverage '.$coverHeadline.')',
                'landing_page_experience' => 'keyword ↔ advertorial H1 overlap'.($mmH1 !== null ? ' ('.$mmH1.')' : ''),
            ],
        ];
    }

    /** @return array<int,string> */
    private function rootHeadlines(string $rootTitle): array
    {
        $out = [$rootTitle];
        foreach (['The %s', '%s Official', '%s Reviews', 'See The %s'] as $tpl) {
            $h = sprintf($tpl, $rootTitle);
            if (mb_strlen($h) <= self::HL_MAX) {
                $out[] = $h;
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function ctaHeadlines(): array
    {
        return ['Watch The Free Video', 'See The Presentation', 'Watch Before It Is Gone', 'Get The Free Briefing', 'Discover The Method'];
    }

    /** @param array<int,string> $headlines */
    private function coverageCount(string $keyword, array $headlines): int
    {
        $k = mb_strtolower($keyword);
        $n = 0;
        foreach ($headlines as $h) {
            if (str_contains(mb_strtolower($h), $k)) {
                $n++;
            }
        }

        return $n;
    }

    /** @param array<int,string> $items */
    private function hasCta(array $items): bool
    {
        foreach ($items as $it) {
            foreach (self::CTA_VERBS as $v) {
                if (str_contains($it, $v)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Mean pairwise distinctness (1 - Jaccard) across headlines — Ad Strength uniqueness proxy. */
    private function uniqueness(array $headlines): float
    {
        $n = count($headlines);
        if ($n < 2) {
            return 1.0;
        }
        $sum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += 1 - $this->jaccard($this->tokens($headlines[$i]), $this->tokens($headlines[$j]));
                $pairs++;
            }
        }

        return $pairs === 0 ? 1.0 : $sum / $pairs;
    }

    private function adStrength(int $hl, int $desc, float $uniqueness, int $coverage, bool $hasCta): string
    {
        $score = 0;
        $score += $hl >= 12 ? 2 : ($hl >= 8 ? 1 : 0);
        $score += $desc >= 4 ? 1 : 0;
        $score += $uniqueness >= 0.6 ? 1 : 0;
        $score += $coverage >= 3 ? 1 : 0;
        $score += $hasCta ? 1 : 0;

        return match (true) {
            $score >= 5 => 'Excellent',
            $score >= 4 => 'Good',
            $score >= 2 => 'Average',
            default => 'Poor',
        };
    }

    private function blocked(string $s): bool
    {
        $l = ' '.mb_strtolower($s).' ';
        foreach (self::COMPLIANCE_BLOCK as $b) {
            if (str_contains($l, ' '.$b.' ') || str_contains($l, ' '.$b)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,string> $existing */
    private function dupes(string $cand, array $existing): bool
    {
        foreach ($existing as $e) {
            if ($this->jaccard($this->tokens($cand), $this->tokens($e)) > 0.8) {
                return true;
            }
        }

        return false;
    }

    private function fit(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));

        return mb_strlen($s) <= $max ? $s : '';
    }

    private function title(string $s): string
    {
        return trim((string) preg_replace_callback('/\b\w/u', fn ($m) => mb_strtoupper($m[0]), mb_strtolower(trim($s))));
    }

    /** @return array<int,string> */
    private function tokens(string $s): array
    {
        $raw = preg_split('/[^a-z0-9]+/', mb_strtolower($s)) ?: [];

        return array_values(array_filter($raw, fn ($t) => $t !== '' && mb_strlen($t) > 1 && ! in_array($t, self::STOP, true)));
    }

    private function recall(array $a, array $b): float
    {
        $a = array_unique($a);
        if ($a === []) {
            return 0.0;
        }
        $set = array_flip($b);
        $hit = 0;
        foreach ($a as $t) {
            if (isset($set[$t])) {
                $hit++;
            }
        }

        return $hit / count($a);
    }

    private function jaccard(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $inter / $union;
    }
}
