<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\ProvenanceWeightCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multh02ProvenanceWeightTest extends TestCase
{
    #[Test]
    public function resolvable_evidence_refs_increase_weight_above_declared_claim(): void
    {
        $out = ProvenanceWeightCalculator::calculate(['ev:1', 'ev:2', 'missing'], ['ev:1', 'ev:2']);

        $this->assertSame(2, $out['resolved_count']);
        $this->assertSame(0.7, $out['multiplier']);
        $this->assertSame(['missing'], $out['dead_refs']);
    }

    #[Test]
    public function dead_refs_count_zero_not_one(): void
    {
        $out = ProvenanceWeightCalculator::calculate(['missing'], ['ev:1']);

        $this->assertSame(0, $out['resolved_count']);
        $this->assertSame(0.5, $out['multiplier']);
    }

    #[Test]
    public function trims_and_dedupes_evidence_and_verified_refs(): void
    {
        $out = ProvenanceWeightCalculator::calculate(
            ['  ev:1  ', 'ev:1', '', 'ev:2'],
            ['ev:1', '  ev:2  ', ''],
        );

        $this->assertSame(2, $out['resolved_count']);
        $this->assertSame([], $out['dead_refs']);
        $this->assertSame(0.7, $out['multiplier']);
    }
}
