<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosThresholdComparator;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosThresholdComparatorTest extends TestCase
{
    public function test_binary_satisfied_preserves_legacy_department_ladder_comparators(): void
    {
        $this->assertTrue(AtlasAaeosThresholdComparator::binarySatisfied('>=', 0.969999999, 0.97));
        $this->assertTrue(AtlasAaeosThresholdComparator::binarySatisfied('<=', 0.100000001, 0.10));
        $this->assertFalse(AtlasAaeosThresholdComparator::binarySatisfied('>', 0.51, 0.50));
        $this->assertFalse(AtlasAaeosThresholdComparator::binarySatisfied('==', 0.50, 0.50));
    }

    public function test_satisfied_supports_full_quality_bar_comparator_set(): void
    {
        $this->assertTrue(AtlasAaeosThresholdComparator::satisfied('>=', 0.93, 0.93));
        $this->assertTrue(AtlasAaeosThresholdComparator::satisfied('<=', 0.02, 0.02));
        $this->assertTrue(AtlasAaeosThresholdComparator::satisfied('==', 0.5, 0.5));
        $this->assertTrue(AtlasAaeosThresholdComparator::satisfied('>', 0.51, 0.5));
        $this->assertTrue(AtlasAaeosThresholdComparator::satisfied('<', 0.49, 0.5));
        $this->assertFalse(AtlasAaeosThresholdComparator::satisfied('!=', 0.1, 0.2));
    }
}
