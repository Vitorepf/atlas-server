<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Deterministic discovery of implication-ordered market pairs. No I/O, no LLM.
 *
 * Emits (implicant A, implied B) pairs where A logically implies B, so fair
 * prices must satisfy P(A) <= P(B). Three Phase-1 families:
 *
 *  1. deadline_monotonic — "X by <earlier date>" implies "X by <later date>"
 *     for the SAME residual question. Only cumulative phrasing (by/before)
 *     qualifies; "in June" is a window, not a cumulative deadline, and is
 *     deliberately NOT matched.
 *  2. nested_threshold — "above N" implies "above M" for N > M (and "below N"
 *     implies "below M" for N < M) for the SAME residual question, which by
 *     construction pins every other token (deadline included) to be identical.
 *  3. group_winner_advances — "<team> wins Group X" implies "<team> advances
 *     from Group X" for an identical residual (same team, same tournament).
 *
 * CITE-OR-OMIT: a pair is emitted only when both sides parse cleanly and the
 * residual stems are byte-identical after placeholder substitution. Negated
 * questions, multiple date/number tokens, mixed direction classes, equal
 * values and unresolvable years all OMIT — a missed pair costs coverage, a
 * wrong pair fabricates an "arbitrage" that does not exist.
 *
 * Context scoping (families 1-2): Polymarket questions can be generic while
 * the subject lives only in the slug ("Map 1 Total Rounds: Over/Under 20.5"
 * appears verbatim across unrelated matches). Question stems alone would
 * collide across events, so pairs additionally require the same slug CONTEXT:
 * the market slug with its own date/threshold phrase, digits, month names,
 * magnitude words and short tokens scrubbed. Same series => same residual
 * slug; different subjects => different residuals => omitted. Live-proven
 * guards: "over/under" combo questions and "hit $N (LOW)"-style suffixes
 * (the trailing low/high token inverts the semantics) are omitted outright.
 */
final class ImplicationRelationParser
{
    public const FAMILY_DEADLINE = 'deadline_monotonic';

    public const FAMILY_THRESHOLD = 'nested_threshold';

    public const FAMILY_GROUP = 'group_winner_advances';

    /** Defensive cap: a degenerate mega-series must not explode quadratically. */
    private const MAX_PAIRS_PER_GROUP = 40;

    /** Max days between a year-inferred deadline and the market's own endDate. */
    private const YEAR_INFERENCE_TOLERANCE_DAYS = 45;

    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    private const MONTH_RE = '(?:january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)';

    // Letter suffixes must touch the number ("200k") and end at a word
    // boundary so the "b" of "...000 by 2026" is never eaten as "billion".
    private const ABOVE_RE = '/\b(above|over|exceeds?|higher than|greater than|more than|at least|reach(?:es)?|hits?|surpass(?:es)?)\s+\$?(\d[\d,]*(?:\.\d+)?)(?:(k|m|b)\b|\s+(thousand|million|billion|trillion)\b)?(?![\d.])/u';

    private const BELOW_RE = '/\b(below|under|less than|lower than|fewer than|at most|dips? to|falls? to|drops? to)\s+\$?(\d[\d,]*(?:\.\d+)?)(?:(k|m|b)\b|\s+(thousand|million|billion|trillion)\b)?(?![\d.])/u';

    private const WIN_GROUP_RE = '/\b(?:wins?|to win)\s+group\s+([a-l])\b/u';

    private const ADVANCE_GROUP_RE = '/\b(?:advances?|qualif(?:y|ies))\s+(?:from|out of)\s+group\s+([a-l])\b/u';

    /**
     * @param  list<array{key: string, question?: string, slug?: string, end_date?: string|null}>  $markets
     * @return list<array{family: string, implicant_key: string, implied_key: string, evidence: array<string, mixed>}>
     */
    public static function pairs(array $markets): array
    {
        $deadlineGroups = [];
        $thresholdGroups = [];
        $groupGroups = [];

        foreach ($markets as $market) {
            $key = (string) ($market['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $question = self::normalize((string) ($market['question'] ?? ''));
            $slugText = self::normalize(str_replace(['-', '_'], ' ', (string) ($market['slug'] ?? '')));
            $text = $question !== '' ? $question : $slugText;
            if ($text === '') {
                continue;
            }

            $endDate = $market['end_date'] ?? null;
            $endDate = is_string($endDate) && $endDate !== '' ? $endDate : null;

            $context = self::contextKey($slugText);

            $deadline = self::deadlineCandidate($text, $endDate);
            if ($deadline !== null) {
                $deadlineGroups[$context.'|'.$deadline['stem']][] = ['key' => $key, 'context' => $context] + $deadline;
            }

            $threshold = self::thresholdCandidate($text);
            if ($threshold !== null) {
                $thresholdGroups[$context.'|'.$threshold['stem']][] = ['key' => $key, 'context' => $context] + $threshold;
            }

            $group = self::groupCandidate($text);
            if ($group !== null) {
                $groupGroups[$group['stem']][] = ['key' => $key, 'context' => null] + $group;
            }
        }

        $pairs = [];
        $seen = [];

        foreach ($deadlineGroups as $members) {
            usort($members, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            self::emitOrdered($pairs, $seen, $members, self::FAMILY_DEADLINE,
                comparable: fn (array $m) => $m['sort'],
                evidenceSide: fn (array $m) => ['raw' => $m['raw'], 'deadline' => $m['date']],
            );
        }

        foreach ($thresholdGroups as $members) {
            $direction = $members[0]['direction'];
            // above: larger threshold implies smaller => implicant first when DESC.
            // below: smaller threshold implies larger => implicant first when ASC.
            usort($members, fn (array $a, array $b) => $direction === 'above'
                ? $b['value'] <=> $a['value']
                : $a['value'] <=> $b['value']);
            self::emitOrdered($pairs, $seen, $members, self::FAMILY_THRESHOLD,
                comparable: fn (array $m) => $m['value'],
                evidenceSide: fn (array $m) => ['raw' => $m['raw'], 'threshold' => $m['value']],
                extra: ['direction' => $direction],
            );
        }

        foreach ($groupGroups as $stem => $members) {
            $winners = array_values(array_filter($members, fn (array $m) => $m['role'] === 'winner'));
            $qualifiers = array_values(array_filter($members, fn (array $m) => $m['role'] === 'qualifier'));
            $emitted = 0;
            foreach ($winners as $winner) {
                foreach ($qualifiers as $qualifier) {
                    if ($winner['key'] === $qualifier['key'] || $emitted >= self::MAX_PAIRS_PER_GROUP) {
                        continue;
                    }
                    $pairKey = $winner['key'].'|'.$qualifier['key'];
                    if (isset($seen[$pairKey])) {
                        continue;
                    }
                    $seen[$pairKey] = true;
                    $emitted++;
                    $pairs[] = [
                        'family' => self::FAMILY_GROUP,
                        'implicant_key' => $winner['key'],
                        'implied_key' => $qualifier['key'],
                        'evidence' => [
                            'stem' => $stem,
                            'group' => $winner['group'],
                            'implicant' => ['raw' => $winner['raw']],
                            'implied' => ['raw' => $qualifier['raw']],
                        ],
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * Emit every ordered pair (i implicant of j) from members already sorted
     * implicant-first, skipping equal comparables (no relation, often a dupe).
     *
     * @param  callable(array): (int|float)  $comparable
     * @param  callable(array): array<string, mixed>  $evidenceSide
     */
    private static function emitOrdered(array &$pairs, array &$seen, array $members, string $family, callable $comparable, callable $evidenceSide, array $extra = []): void
    {
        $count = count($members);
        if ($count < 2) {
            return;
        }

        $emitted = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($emitted >= self::MAX_PAIRS_PER_GROUP) {
                    return;
                }
                $a = $members[$i];
                $b = $members[$j];
                if ($a['key'] === $b['key'] || $comparable($a) === $comparable($b)) {
                    continue;
                }
                $pairKey = $a['key'].'|'.$b['key'];
                if (isset($seen[$pairKey])) {
                    continue;
                }
                $seen[$pairKey] = true;
                $emitted++;
                $pairs[] = [
                    'family' => $family,
                    'implicant_key' => $a['key'],
                    'implied_key' => $b['key'],
                    'evidence' => $extra + [
                        'stem' => $a['stem'],
                        'context' => $a['context'],
                        'implicant' => $evidenceSide($a),
                        'implied' => $evidenceSide($b),
                    ],
                ];
            }
        }
    }

    /**
     * Family 1: cumulative deadline. Exactly one by/before date expression,
     * no negation, resolvable year — anything else omits.
     *
     * @return array{stem: string, date: string, sort: int, raw: string}|null
     */
    private static function deadlineCandidate(string $text, ?string $endDate): ?array
    {
        if (self::isNegated($text)) {
            return null;
        }

        if (preg_match_all(self::deadlineRegex(), $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null; // zero (no deadline) or several (ambiguous): omit
        }

        $raw = $matches[0][0][0];
        $offset = (int) $matches[0][0][1];
        $monthName = $matches[1][0][0] ?? '';
        $dayRaw = $matches[2][0][0] ?? '';
        $yearRaw = $matches[3][0][0] ?? '';
        $yearOnly = $matches[4][0][0] ?? '';

        if ($yearOnly !== '') {
            $year = (int) $yearOnly;
            $month = 12;
            $day = 31;
        } else {
            $month = self::MONTHS[$monthName] ?? null;
            if ($month === null) {
                return null;
            }
            $year = $yearRaw !== '' ? (int) $yearRaw : self::inferYear($month, $dayRaw !== '' ? (int) $dayRaw : null, $endDate);
            if ($year === null) {
                return null; // year not resolvable without guessing: omit
            }
            $day = $dayRaw !== '' ? (int) $dayRaw : self::daysInMonth($month, $year);
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $stem = self::stemKey(substr_replace($text, ' «date» ', $offset, strlen($raw)));

        return [
            'stem' => $stem,
            'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'sort' => $year * 10000 + $month * 100 + $day,
            'raw' => $raw,
        ];
    }

    /**
     * Year inference for titles like "by June 30" (no explicit year): try the
     * market's endDate year and its neighbours; accept only if exactly one
     * candidate lands within tolerance of the endDate. No endDate => omit.
     */
    private static function inferYear(int $month, ?int $day, ?string $endDate): ?int
    {
        if ($endDate === null) {
            return null;
        }

        try {
            $end = new \DateTimeImmutable($endDate);
        } catch (\Throwable) {
            return null;
        }

        $endYear = (int) $end->format('Y');
        $winner = null;
        foreach ([$endYear - 1, $endYear, $endYear + 1] as $candidate) {
            $candidateDay = $day ?? self::daysInMonth($month, $candidate);
            if (! checkdate($month, $candidateDay, $candidate)) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $candidate, $month, $candidateDay));
            if ($date === false) {
                continue;
            }
            $deltaDays = abs((int) $end->diff($date)->format('%a'));
            if ($deltaDays <= self::YEAR_INFERENCE_TOLERANCE_DAYS) {
                if ($winner !== null) {
                    return null; // two plausible years: ambiguous, omit
                }
                $winner = $candidate;
            }
        }

        return $winner;
    }

    /**
     * Family 2: exactly one above-class OR below-class numeric token,
     * no negation. The number is replaced by a class placeholder so the stem
     * also encodes direction; mixed classes never share a stem.
     *
     * @return array{stem: string, direction: string, value: float, raw: string}|null
     */
    private static function thresholdCandidate(string $text): ?array
    {
        if (self::isNegated($text)) {
            return null;
        }

        // "Over/Under N" markets name BOTH sides in one question; which side the
        // primary token prices is not derivable from the text. Omit outright.
        if (preg_match('/\bover\s*\/\s*under\b|\bunder\s*\/\s*over\b|\bo\/u\b/u', $text) === 1) {
            return null;
        }

        $aboveCount = preg_match_all(self::ABOVE_RE, $text, $aboveMatches, PREG_OFFSET_CAPTURE);
        $belowCount = preg_match_all(self::BELOW_RE, $text, $belowMatches, PREG_OFFSET_CAPTURE);
        if (($aboveCount + $belowCount) !== 1) {
            return null; // none, several, or a range ("above X but below Y"): omit
        }

        $direction = $aboveCount === 1 ? 'above' : 'below';
        $matches = $aboveCount === 1 ? $aboveMatches : $belowMatches;

        $raw = $matches[0][0][0];
        $offset = (int) $matches[0][0][1];
        $number = (string) $matches[2][0][0];
        $suffix = strtolower((string) (($matches[3][0][0] ?? '') !== '' ? $matches[3][0][0] : ($matches[4][0][0] ?? '')));

        // "hit $6,500 (LOW)": a low/high token right after the number flips the
        // event from a level-exceedance to a touch-from-above (or bracket) —
        // direction is no longer derivable from the prefix verb. Omit (live-proven
        // false-arb source on the SPX low series).
        $tail = substr($text, $offset + strlen($raw));
        if (preg_match('/^\s*[\(\[]?\s*(?:an?\s+)?(?:intraday\s+)?(?:low|high|bottom|top|floor|ceiling|peak)\b/u', $tail) === 1) {
            return null;
        }

        $value = (float) str_replace(',', '', $number);
        $value *= match ($suffix) {
            'k', 'thousand' => 1_000.0,
            'm', 'million' => 1_000_000.0,
            'b', 'billion' => 1_000_000_000.0,
            'trillion' => 1_000_000_000_000.0,
            default => 1.0,
        };
        if (! is_finite($value) || $value <= 0.0) {
            return null;
        }

        $stem = self::stemKey(substr_replace($text, ' «'.$direction.' n» ', $offset, strlen($raw)));

        return [
            'stem' => $stem,
            'direction' => $direction,
            'value' => $value,
            'raw' => $raw,
        ];
    }

    /**
     * Family 3: "wins Group X" (implicant) vs "advances/qualifies from Group X"
     * (implied). The residual stem pins team and tournament to be identical.
     *
     * @return array{stem: string, role: string, group: string, raw: string}|null
     */
    private static function groupCandidate(string $text): ?array
    {
        if (self::isNegated($text)) {
            return null;
        }

        $winCount = preg_match_all(self::WIN_GROUP_RE, $text, $winMatches, PREG_OFFSET_CAPTURE);
        $advanceCount = preg_match_all(self::ADVANCE_GROUP_RE, $text, $advanceMatches, PREG_OFFSET_CAPTURE);
        if (($winCount + $advanceCount) !== 1) {
            return null;
        }

        $role = $winCount === 1 ? 'winner' : 'qualifier';
        $matches = $winCount === 1 ? $winMatches : $advanceMatches;
        $raw = $matches[0][0][0];
        $offset = (int) $matches[0][0][1];
        $letter = strtolower((string) $matches[1][0][0]);

        $stem = self::stemKey(substr_replace($text, ' «group '.$letter.'» ', $offset, strlen($raw)));

        return [
            'stem' => $stem,
            'role' => $role,
            'group' => $letter,
            'raw' => $raw,
        ];
    }

    private static function deadlineRegex(): string
    {
        return '/\b(?:by|before)\s+(?:the\s+)?(?:end\s+of\s+)?(?:('.self::MONTH_RE.')\.?(?:\s+(\d{1,2})(?!\d)(?:st|nd|rd|th)?)?(?:\s*,?\s*(\d{4}))?|(\d{4}))\b/u';
    }

    /**
     * Slug-derived series identity for families 1-2: the market slug with its
     * own variable phrase (date/threshold), digits, month names, magnitude
     * words and short tokens scrubbed. Same series => same residual; generic
     * questions from unrelated events get DIFFERENT residuals and never pair.
     */
    private static function contextKey(string $slugText): string
    {
        $text = ' '.$slugText.' ';
        $text = (string) preg_replace(self::deadlineRegex(), ' ', $text);
        $text = (string) preg_replace(self::ABOVE_RE, ' ', $text);
        $text = (string) preg_replace(self::BELOW_RE, ' ', $text);
        $text = (string) preg_replace('/\d+/', ' ', $text);
        $text = (string) preg_replace('/\b'.self::MONTH_RE.'\b/u', ' ', $text);
        $text = (string) preg_replace('/\b(?:thousand|million|billion|trillion)\b/u', ' ', $text);
        $text = (string) preg_replace('/[^a-z]+/u', ' ', $text);

        $tokens = array_filter(explode(' ', trim($text)), fn (string $t) => mb_strlen($t) > 2);

        return implode(' ', $tokens);
    }

    /**
     * Negation flips or breaks the implication direction; Phase 1 simply omits.
     */
    private static function isNegated(string $text): bool
    {
        return preg_match('/\b(not|never|no longer|fail(?:s|ed)?|without)\b|n[\'’]t\b/u', $text) === 1;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['–', '—', '−'], '-', $text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Canonical residual: strip everything but letters, digits and placeholder
     * markers so punctuation/spacing differences cannot fake a mismatch.
     */
    private static function stemKey(string $text): string
    {
        $text = (string) preg_replace('/[^a-z0-9«»]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function daysInMonth(int $month, int $year): int
    {
        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    }
}
