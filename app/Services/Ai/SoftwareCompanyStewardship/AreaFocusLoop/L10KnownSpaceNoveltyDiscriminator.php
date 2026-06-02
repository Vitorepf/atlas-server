<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S158 — L10 Known-Space Novelty Discriminator (block: L10 Generative Engineering Guard).
 *
 * Pure, deterministic, zero-dependency. It compares a generative paradigm
 * candidate against the corpus of known engineering patterns and decides whether
 * the candidate is genuinely outside the known space (with evidence) or just
 * renamed existing work.
 *
 * Canon: "New means outside known space with evidence, not renamed existing work."
 *
 * Ordered rules (each returns the same four keys):
 *   1. An exact known-pattern signature match rejects novelty (renamed existing
 *      work) -> novelty_status=known_existing, blocker exact_known_pattern_match.
 *   2. An insufficient known corpus cannot prove "outside known space" ->
 *      novelty_status=unknown_not_new, blocker insufficient_known_corpus.
 *   3. A novelty_score below threshold blocks (too close to known space) ->
 *      novelty_status=known_existing, blocker novelty_score_below_threshold.
 *   Otherwise novelty_status=novel_outside_known_space with no blockers.
 *
 * novelty_score is a 0..1 distance from the nearest known pattern (1 - nearest
 * mechanism-set Jaccard similarity), dampened when the candidate carries no
 * explicit novelty evidence. It never exceeds 1.0 for any input.
 *
 * No I/O, DB, Eloquent, facades, HTTP/provider, git/Process, filesystem, clock or
 * randomness. Every returned field is computed purely from the method inputs.
 */
final class L10KnownSpaceNoveltyDiscriminator
{
    public const SCHEMA_VERSION = 'atlas.loop.l10_known_space_novelty.v1';

    /** Minimum number of known patterns required before novelty can be asserted. */
    public const MIN_KNOWN_CORPUS = 3;

    /** novelty_score strictly below this blocks the candidate as renamed work. */
    public const NOVELTY_SCORE_THRESHOLD = 0.6;

    /** Distance multiplier applied when the candidate carries no novelty evidence. */
    public const NO_EVIDENCE_PENALTY = 0.5;

    /** Maximum number of nearest known patterns reported. */
    public const NEAREST_LIMIT = 3;

    public const STATUS_NOVEL = 'novel_outside_known_space';
    public const STATUS_KNOWN = 'known_existing';
    public const STATUS_UNKNOWN_NOT_NEW = 'unknown_not_new';

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int|string,mixed>  $knownPatterns
     * @return array{
     *     schema_version: string,
     *     novelty_status: string,
     *     nearest_known_patterns: list<string>,
     *     novelty_score: float,
     *     blockers: list<string>
     * }
     */
    public function discriminate(array $candidate, array $knownPatterns): array
    {
        $candidateSignature = $this->signatureOf($candidate);
        $candidateMechanisms = $this->mechanismsOf($candidate);
        $hasNoveltyEvidence = $this->hasNoveltyEvidence($candidate);

        $patterns = $this->normalizePatterns($knownPatterns);

        $ranked = $this->rankBySimilarity($candidateMechanisms, $patterns);
        $nearest = $this->nearestIds($ranked);
        $nearestSimilarity = $ranked === [] ? 0.0 : $ranked[0]['similarity'];

        $noveltyScore = $this->noveltyScore($nearestSimilarity, $hasNoveltyEvidence);

        if ($this->matchesExactKnownPattern($candidateSignature, $patterns)) {
            return $this->result(self::STATUS_KNOWN, $nearest, $noveltyScore, ['exact_known_pattern_match']);
        }

        if (count($patterns) < self::MIN_KNOWN_CORPUS) {
            return $this->result(self::STATUS_UNKNOWN_NOT_NEW, $nearest, $noveltyScore, ['insufficient_known_corpus']);
        }

        if ($noveltyScore < self::NOVELTY_SCORE_THRESHOLD) {
            return $this->result(self::STATUS_KNOWN, $nearest, $noveltyScore, ['novelty_score_below_threshold']);
        }

        return $this->result(self::STATUS_NOVEL, $nearest, $noveltyScore, []);
    }

    /**
     * @param  list<string>  $nearest
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     novelty_status: string,
     *     nearest_known_patterns: list<string>,
     *     novelty_score: float,
     *     blockers: list<string>
     * }
     */
    private function result(string $status, array $nearest, float $noveltyScore, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'novelty_status' => $status,
            'nearest_known_patterns' => $nearest,
            'novelty_score' => $noveltyScore,
            'blockers' => $blockers,
        ];
    }

    /**
     * novelty_score = (1 - nearest similarity) dampened when no novelty evidence.
     *
     * Result is always within [0.0, 1.0]: nearest similarity is clamped to [0,1],
     * so (1 - similarity) is in [0,1], and the evidence factor is at most 1.0.
     */
    private function noveltyScore(float $nearestSimilarity, bool $hasNoveltyEvidence): float
    {
        $similarity = $this->clampUnit($nearestSimilarity);
        $distance = 1.0 - $similarity;
        $evidenceFactor = $hasNoveltyEvidence ? 1.0 : self::NO_EVIDENCE_PENALTY;

        return round($this->clampUnit($distance * $evidenceFactor), 4);
    }

    /**
     * @param  list<string>  $candidateMechanisms
     * @param  list<array{id:string,signature:string,mechanisms:list<string>}>  $patterns
     * @return list<array{id:string,similarity:float}>
     */
    private function rankBySimilarity(array $candidateMechanisms, array $patterns): array
    {
        $ranked = [];
        foreach ($patterns as $index => $pattern) {
            $ranked[] = [
                'id' => $pattern['id'],
                'similarity' => $this->jaccard($candidateMechanisms, $pattern['mechanisms']),
                'order' => $index,
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            if ($a['similarity'] === $b['similarity']) {
                return $a['order'] <=> $b['order'];
            }

            return $b['similarity'] <=> $a['similarity'];
        });

        return array_map(
            static fn (array $entry): array => ['id' => $entry['id'], 'similarity' => $entry['similarity']],
            $ranked,
        );
    }

    /**
     * @param  list<array{id:string,similarity:float}>  $ranked
     * @return list<string>
     */
    private function nearestIds(array $ranked): array
    {
        $ids = [];
        foreach ($ranked as $entry) {
            if ($entry['similarity'] <= 0.0) {
                continue;
            }

            $ids[] = $entry['id'];
            if (count($ids) >= self::NEAREST_LIMIT) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Jaccard similarity of two mechanism sets: |intersection| / |union|.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] && $right === []) {
            return 0.0;
        }

        $intersection = 0;
        foreach ($left as $token) {
            if (in_array($token, $right, true)) {
                $intersection++;
            }
        }

        $union = count($left) + count($right) - $intersection;
        if ($union <= 0) {
            return 0.0;
        }

        return $this->clampUnit($intersection / $union);
    }

    /**
     * @param  list<array{id:string,signature:string,mechanisms:list<string>}>  $patterns
     */
    private function matchesExactKnownPattern(string $candidateSignature, array $patterns): bool
    {
        if ($candidateSignature === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($pattern['signature'] !== '' && $pattern['signature'] === $candidateSignature) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string,mixed>  $knownPatterns
     * @return list<array{id:string,signature:string,mechanisms:list<string>}>
     */
    private function normalizePatterns(array $knownPatterns): array
    {
        $patterns = [];
        $fallback = 0;
        foreach ($knownPatterns as $key => $pattern) {
            if (! is_array($pattern)) {
                $fallback++;

                continue;
            }

            $signature = $this->normalizeToken($pattern['signature'] ?? '');
            $mechanisms = $this->mechanismsOf($pattern);

            $id = $this->normalizeToken($pattern['id'] ?? '');
            if ($id === '') {
                $id = $signature !== '' ? $signature : 'pattern_'.$fallback;
                $fallback++;
            }

            $patterns[] = [
                'id' => $id,
                'signature' => $signature,
                'mechanisms' => $mechanisms,
            ];
        }

        return $patterns;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function signatureOf(array $payload): string
    {
        return $this->normalizeToken($payload['signature'] ?? $payload['paradigm_signature'] ?? '');
    }

    /**
     * Mechanism set: deduplicated, normalized engineering primitives.
     *
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function mechanismsOf(array $payload): array
    {
        $raw = $payload['mechanisms'] ?? $payload['concepts'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $tokens = [];
        foreach ($raw as $value) {
            $token = $this->normalizeToken($value);
            if ($token !== '' && ! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function hasNoveltyEvidence(array $candidate): bool
    {
        $refs = $candidate['novelty_evidence'] ?? $candidate['evidence_refs'] ?? [];
        if (! is_array($refs)) {
            return false;
        }

        foreach ($refs as $ref) {
            if ($this->normalizeToken($ref) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeToken(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower(trim($value));
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
