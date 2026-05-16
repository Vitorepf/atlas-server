<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use PHPUnit\Framework\TestCase;

final class ObservedSignalsTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_constructs_with_minimum_required(): void
    {
        $os = new ObservedSignals(fileCount: 3, layersTouched: 2, riskKeywords: ['auth']);

        $this->assertSame(3, $os->fileCount);
        $this->assertNull($os->contextRequiredChars);
        $this->assertNull($os->priorFailureInArea);
        $this->assertSame('atlas.dev.components.observed_signals.v1', $os->schemaVersion());
    }

    public function test_canonical_array_is_sorted(): void
    {
        $os = new ObservedSignals(fileCount: 3, layersTouched: 2, riskKeywords: ['auth', 'billing']);
        $expected = [
            'context_required_chars', 'file_count', 'layers_touched',
            'prior_failure_in_area', 'risk_keywords', 'test_coverage_gap',
        ];
        $this->assertSame($expected, array_keys($os->toCanonicalArray()));
        $this->assertCanonicalArrayKeysSorted($os);
    }

    public function test_round_trip_via_from_array(): void
    {
        $os = new ObservedSignals(
            fileCount: 5,
            layersTouched: 3,
            riskKeywords: ['migration'],
            contextRequiredChars: 18000,
            priorFailureInArea: true,
            testCoverageGap: false,
        );

        $rebuilt = ObservedSignals::fromArray(json_decode($os->toJson(), true));
        $this->assertHashStable($os, $rebuilt);
        $this->assertJsonRoundtripStable($os);
    }

    public function test_hash_differs_when_risk_keyword_added(): void
    {
        $a = new ObservedSignals(fileCount: 1, layersTouched: 1, riskKeywords: ['auth']);
        $b = new ObservedSignals(fileCount: 1, layersTouched: 1, riskKeywords: ['auth', 'billing']);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
