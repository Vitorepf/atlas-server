<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Support\AdaptiveSizer;
use PHPUnit\Framework\TestCase;

final class AdaptiveSizerTest extends TestCase
{
    public function test_knee_is_bounded_between_min_and_max(): void
    {
        $values = array_map(static fn (int $i): float => 100.0 / ($i + 1), range(0, 99)); // descending
        $knee = AdaptiveSizer::knee($values, 3, 20);

        $this->assertGreaterThanOrEqual(3, $knee);
        $this->assertLessThanOrEqual(20, $knee);
    }

    public function test_knee_never_exceeds_item_count(): void
    {
        $knee = AdaptiveSizer::knee([5.0, 1.0], 1, 40);
        $this->assertGreaterThanOrEqual(1, $knee);
        $this->assertLessThanOrEqual(2, $knee);
        $this->assertSame(0, AdaptiveSizer::knee([], 0, 40));
    }

    public function test_knee_finds_the_elbow_of_a_sharp_drop(): void
    {
        // 3 dominant items then a long flat tail -> knee should land near 3.
        $values = array_merge([100.0, 90.0, 80.0], array_fill(0, 50, 1.0));
        $knee = AdaptiveSizer::knee($values, 1, 40);

        $this->assertGreaterThanOrEqual(3, $knee);
        $this->assertLessThanOrEqual(8, $knee);
    }

    public function test_simhash_is_deterministic_and_similar_for_similar_text(): void
    {
        $a = AdaptiveSizer::simhash('the quick brown fox jumps over the lazy dog');
        $aAgain = AdaptiveSizer::simhash('the quick brown fox jumps over the lazy dog');
        $near = AdaptiveSizer::simhash('the quick brown fox jumps over the lazy cat');
        $far = AdaptiveSizer::simhash('completely unrelated tokens here zzzzz qqqqq');

        $this->assertSame($a, $aAgain, 'simhash must be deterministic');
        $this->assertSame(16, strlen($a), 'simhash is 64-bit => 16 hex chars');
        $this->assertLessThan(
            AdaptiveSizer::hamming($a, $far),
            AdaptiveSizer::hamming($a, $near),
            'a near-duplicate must be closer than an unrelated string',
        );
    }

    public function test_hamming_of_identical_is_zero(): void
    {
        $h = AdaptiveSizer::simhash('atlas compression layer');
        $this->assertSame(0, AdaptiveSizer::hamming($h, $h));
    }
}
