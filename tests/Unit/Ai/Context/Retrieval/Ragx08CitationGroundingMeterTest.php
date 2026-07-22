<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\CitationGroundingMeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Ragx08CitationGroundingMeterTest extends TestCase
{
    #[Test]
    public function cited_delivered_refs_count_as_grounded(): void
    {
        $out = CitationGroundingMeter::measure([
            ['delivered_refs' => ['memory:aaa', 'graph:bbb'], 'response' => 'Used ref=memory:aaa'],
        ]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(1.0, $out['grounding_rate']);
        $this->assertSame(1.0, $out['citation_coverage']);
    }

    #[Test]
    public function unsupported_citation_is_counted_separately(): void
    {
        $out = CitationGroundingMeter::measure([
            ['delivered_refs' => ['memory:aaa'], 'response' => 'Used ref=memory:missing'],
        ]);

        $this->assertSame(0.0, $out['grounding_rate']);
        $this->assertSame(1, $out['unsupported_citation_count']);
    }

    #[Test]
    public function no_citations_is_insufficient_not_perfect(): void
    {
        $out = CitationGroundingMeter::measure([
            ['delivered_refs' => ['memory:aaa'], 'response' => 'No references here'],
        ]);

        $this->assertSame('insufficient_signal', $out['status']);
        $this->assertNull($out['grounding_rate']);
    }
}
