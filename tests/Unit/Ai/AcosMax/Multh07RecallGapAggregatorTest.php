<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\RecallGapAggregator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multh07RecallGapAggregatorTest extends TestCase
{
    #[Test]
    public function repeated_empty_recalls_emit_one_gap_candidate(): void
    {
        $out = RecallGapAggregator::aggregate([
            ['query' => 'missing concept', 'top_score' => 0.0],
            ['query' => 'missing concept', 'top_score' => 0.1],
            ['query' => 'missing concept', 'top_score' => 0.2],
        ], minOccurrences: 3);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(3, $out['candidates'][0]['occurrences']);
        $this->assertArrayNotHasKey('query', $out['candidates'][0]);
    }

    #[Test]
    public function singleton_query_does_not_emit_candidate(): void
    {
        $out = RecallGapAggregator::aggregate([
            ['query' => 'one off', 'top_score' => 0.0],
        ], minOccurrences: 2);

        $this->assertSame('insufficient_signal', $out['status']);
        $this->assertSame([], $out['candidates']);
    }

    #[Test]
    public function successful_recall_does_not_record_gap(): void
    {
        $out = RecallGapAggregator::aggregate([
            ['query' => 'known', 'top_score' => 0.9],
            ['query' => 'known', 'top_score' => 0.9],
        ], minOccurrences: 2);

        $this->assertSame([], $out['candidates']);
    }
}
