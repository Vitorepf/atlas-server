<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\SearchTermWasteMiner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the search-term n-gram waste miner: it cuts proven money-leaks (lexical junk + zero-conversion
 * n-grams) WITHOUT ever blocking the campaign's own qualified traffic — a negative is never emitted for
 * an n-gram that co-occurs with an owned-root or converting term, and never as a single word.
 */
class SearchTermWasteMinerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function artifacts(): array
    {
        return ['mechanism' => 'triple hormone drops protocol', 'slogan' => 'make america skinny again',
            'celebrities' => ['melania trump'], 'product_name' => 'lipo bliss'];
    }

    public function test_lexical_junk_is_negated(): void
    {
        $terms = [
            ['term' => 'free weight loss protocol', 'cost' => 35, 'conversions' => 0],
            ['term' => 'what is retatrutide', 'cost' => 22, 'conversions' => 0],
            ['term' => 'weight loss jobs near me', 'cost' => 14, 'conversions' => 0],
            ['term' => 'lipo bliss amazon', 'cost' => 9, 'conversions' => 0],
            ['term' => 'best diet meme', 'cost' => 5, 'conversions' => 0],
        ];
        $ngrams = array_column((new SearchTermWasteMiner)->mine($terms, $this->artifacts())['negatives'], 'ngram');
        $this->assertContains('free', $ngrams);
        $this->assertContains('what is', $ngrams);
        $this->assertContains('jobs', $ngrams);
        $this->assertContains('lipo bliss', $ngrams);
        $this->assertContains('meme', $ngrams);
    }

    public function test_never_blocks_owned_root_or_converting_traffic(): void
    {
        $terms = [
            ['term' => 'make america skinny again weight loss', 'cost' => 8, 'conversions' => 1], // owned root + converted
            ['term' => 'weight loss jobs near me', 'cost' => 30, 'conversions' => 0],
            ['term' => 'cheap weight loss pills', 'cost' => 40, 'conversions' => 0],
        ];
        $ngrams = array_column((new SearchTermWasteMiner)->mine($terms, $this->artifacts(), ['cost_threshold' => 20])['negatives'], 'ngram');
        // "weight loss" co-occurs with the converting owned-root term → must be protected.
        $this->assertNotContains('weight loss', $ngrams);
        $this->assertNotContains('weight', $ngrams);
        $this->assertNotContains('loss', $ngrams);
    }

    public function test_performance_negative_is_multiword_not_single_token(): void
    {
        // Two zero-conversion terms sharing the 2-gram "green tea" → that 2-gram is the money-leak.
        $terms = [
            ['term' => 'green tea fat burner', 'cost' => 30, 'conversions' => 0],
            ['term' => 'green tea weight pills', 'cost' => 25, 'conversions' => 0],
        ];
        $r = (new SearchTermWasteMiner)->mine($terms, $this->artifacts(), ['cost_threshold' => 20]);
        $perf = array_values(array_filter($r['negatives'], fn ($n) => ($n['reason'] ?? '') === 'zero_conversions_with_cost'));
        $ngrams = array_column($perf, 'ngram');
        $this->assertContains('green tea', $ngrams, 'the shared multi-word money-leak must be negated');
        foreach ($perf as $n) {
            $this->assertGreaterThanOrEqual(2, str_word_count($n['ngram']), 'performance negatives must be multi-word');
        }
        $this->assertNotContains('green', $ngrams); // never a single-word performance negative
    }

    public function test_waste_cost_and_summary_are_reported(): void
    {
        $terms = [['term' => 'free protocol', 'cost' => 35, 'conversions' => 0]];
        $r = (new SearchTermWasteMiner)->mine($terms, $this->artifacts());
        $this->assertGreaterThan(0, $r['waste_cost']);
        $this->assertSame(1, $r['summary']['terms_analyzed']);
    }

    public function test_terms_without_owned_root_are_flagged(): void
    {
        $terms = ['generic supplement store'];
        $r = (new SearchTermWasteMiner)->mine($terms, $this->artifacts());
        // Not lexical junk, but no owned root → surfaced for review.
        $this->assertContains('generic supplement store', $r['no_owned_root']);
    }
}
