<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\TestOs;

use App\Services\Ai\Foundry\TestOs\StringLengthEquivalenceClassDeriver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StringLengthEquivalenceClassDeriverTest extends TestCase
{
    private StringLengthEquivalenceClassDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new StringLengthEquivalenceClassDeriver();
    }

    public function testMinOneMaxFiveYieldsFourClassesWithExpectedLengthsAndKinds(): void
    {
        $result = $this->deriver->derive([
            'name' => 'title',
            'type' => 'string',
            'min' => 1,
            'max' => 5,
        ]);

        $classes = $result['classes'];

        $this->assertCount(4, $classes);

        $this->assertSame([0, 1, 5, 6], array_column($classes, 'length'));
        $this->assertSame(
            ['invalid', 'boundary', 'boundary', 'invalid'],
            array_column($classes, 'kind'),
        );
        $this->assertSame(
            ['empty', 'min-len', 'max-len', 'over-max'],
            array_column($classes, 'label'),
        );
    }

    public function testEachRepresentativeStrlenEqualsDeclaredLength(): void
    {
        $result = $this->deriver->derive([
            'name' => 'slug',
            'type' => 'string',
            'min' => 2,
            'max' => 9,
        ]);

        foreach ($result['classes'] as $class) {
            $this->assertSame($class['length'], strlen($class['representative']));
        }

        $byLabel = array_column($result['classes'], null, 'label');

        $this->assertSame('', $byLabel['empty']['representative']);
        $this->assertSame('aa', $byLabel['min-len']['representative']);
        $this->assertSame('aaaaaaaaa', $byLabel['max-len']['representative']);
        $this->assertSame('aaaaaaaaaa', $byLabel['over-max']['representative']);
    }

    public function testEmptyClassIsNotInvalidWhenMinIsZero(): void
    {
        $result = $this->deriver->derive([
            'name' => 'note',
            'type' => 'string',
            'min' => 0,
            'max' => 4,
        ]);

        $empty = $result['classes'][0];

        $this->assertSame('empty', $empty['label']);
        $this->assertSame(0, $empty['length']);
        $this->assertNotSame('invalid', $empty['kind']);
        $this->assertSame('boundary', $empty['kind']);
        $this->assertSame('', $empty['representative']);
    }

    public function testNegativeMinThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'name' => 'broken',
            'type' => 'string',
            'min' => -1,
            'max' => 5,
        ]);
    }

    public function testMaxLessThanMinThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'name' => 'inverted',
            'type' => 'string',
            'min' => 5,
            'max' => 3,
        ]);
    }

    public function testAbsentBoundsThrowInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'name' => 'no_bounds',
            'type' => 'string',
        ]);
    }
}
