<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\Ambiguity;

use App\Services\Ai\Mission\Support\MissionPromptTokenizer;

final class VagueTermDensityScorer
{
    /**
     * Bilingual (PT/EN) lexicon of vague, under-specified hedge terms. A prompt
     * peppered with these words signals an ambiguous request that is hard to
     * decompose into a concrete work order.
     *
     * Matching is whole-word (word-boundary) only: a lexicon entry must appear
     * as a standalone token, so "melhore" never matches "melhor" and a token
     * that merely contains a vague word as a substring is not counted.
     *
     * @var list<string>
     */
    private const VAGUE_TERMS = [
        // PT
        'alguns',
        'algumas',
        'varios',
        'varias',
        'melhor',
        'melhorar',
        'otimo',
        'otimizar',
        'algo',
        'coisa',
        'coisas',
        'qualquer',
        'talvez',
        'etc',
        // EN
        'various',
        'several',
        'some',
        'better',
        'optimize',
        'improve',
        'stuff',
        'something',
        'somehow',
        'maybe',
        'etcetera',
    ];

    /**
     * Saturation cap: once this many DISTINCT vague terms appear the density is
     * considered fully saturated. The raw score is (distinct matches / cap) and
     * is clamped to 1.0 so additional vague terms never push it above the unit
     * range.
     */
    private const SATURATION_CAP = 4;

    /**
     * Returns the vague-term density of a raw prompt in the range [0.0, 1.0].
     *
     * The score is the number of DISTINCT vague terms matched (each term counts
     * at most once, regardless of how many times it is repeated) divided by the
     * fixed saturation cap of 4, clamped to a maximum of 1.0:
     *
     *   density = min(distinctVagueTerms, cap) / cap
     *
     * An empty or whitespace-only prompt has zero matches and therefore a
     * density of exactly 0.0.
     */
    public function density(string $rawPrompt): float
    {
        $distinctVagueTerms = $this->countDistinctVagueTerms($rawPrompt);

        $bounded = min($distinctVagueTerms, self::SATURATION_CAP);

        return $bounded / self::SATURATION_CAP;
    }

    private function countDistinctVagueTerms(string $rawPrompt): int
    {
        $tokens = MissionPromptTokenizer::promptWords($rawPrompt);

        if ($tokens === []) {
            return 0;
        }

        $matched = [];

        foreach ($tokens as $token) {
            if (in_array($token, self::VAGUE_TERMS, true)) {
                $matched[$token] = true;
            }
        }

        return count($matched);
    }

}
