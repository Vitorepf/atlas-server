<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\TestOs;

use App\Services\Ai\Foundry\TestOs\NumericRangeEquivalenceClassDeriver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NumericRangeEquivalenceClassDeriverTest extends TestCase
{
    private NumericRangeEquivalenceClassDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new NumericRangeEquivalenceClassDeriver();
    }

    public function testIntegerRangeDerivesFiveClassesWithComputedRepresentativesAndKinds(): void
    {
        $result = $this->deriver->derive([
            'name' => 'retries',
            'type' => 'int',
            'min' => 0,
            'max' => 10,
        ]);

        $this->assertSame('atlas.foundry.testos.numeric_range_equivalence_classes.v1', $result['schema_version']);

        $classes = $result['classes'];
        $this->assertCount(5, $classes);

        $representatives = array_map(static fn (array $class): int|float => $class['representative'], $classes);
        $kinds = array_map(static fn (array $class): string => $class['kind'], $classes);
        $labels = array_map(static fn (array $class): string => $class['label'], $classes);

        $this->assertSame([-1, 0, 5, 10, 11], $representatives);
        $this->assertSame(['invalid', 'boundary', 'valid', 'boundary', 'invalid'], $kinds);
        $this->assertSame(['below_min', 'min_boundary', 'midpoint', 'max_boundary', 'above_max'], $labels);
    }

    public function testIntegerRepresentativesStayIntegerTyped(): void
    {
        $result = $this->deriver->derive([
            'type' => 'int',
            'min' => 0,
            'max' => 10,
        ]);

        foreach ($result['classes'] as $class) {
            $this->assertIsInt($class['representative']);
        }
    }

    public function testFloatRangeKeepsMidpointFractional(): void
    {
        $result = $this->deriver->derive([
            'name' => 'ratio',
            'type' => 'float',
            'min' => 1.0,
            'max' => 2.0,
        ]);

        $classes = $result['classes'];
        $midpoint = $classes[2];

        $this->assertSame('midpoint', $midpoint['label']);
        $this->assertSame('valid', $midpoint['kind']);
        $this->assertSame(1.5, $midpoint['representative']);

        $representatives = array_map(static fn (array $class): int|float => $class['representative'], $classes);
        $this->assertSame([0.0, 1.0, 1.5, 2.0, 3.0], $representatives);
    }

    public function testIntegerMidpointUsesIntegerDivisionTruncation(): void
    {
        $result = $this->deriver->derive([
            'type' => 'int',
            'min' => 0,
            'max' => 3,
        ]);

        $midpoint = $result['classes'][2];

        $this->assertSame('midpoint', $midpoint['label']);
        $this->assertSame(1, $midpoint['representative']);
        $this->assertSame('valid', $midpoint['kind']);
    }

    public function testEqualOrInvertedBoundsThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'type' => 'int',
            'min' => 5,
            'max' => 5,
        ]);
    }

    public function testMinGreaterThanMaxThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'type' => 'int',
            'min' => 8,
            'max' => 2,
        ]);
    }

    public function testMissingMinThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'type' => 'int',
            'max' => 10,
        ]);
    }

    public function testMissingMaxThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'type' => 'int',
            'min' => 0,
        ]);
    }
}
