<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\TestOs;

use App\Services\Ai\Foundry\TestOs\EnumEquivalenceClassDeriver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EnumEquivalenceClassDeriverTest extends TestCase
{
    private EnumEquivalenceClassDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new EnumEquivalenceClassDeriver();
    }

    public function testThreeMemberEnumYieldsThreeValidClassesPlusOneInvalid(): void
    {
        $result = $this->deriver->derive([
            'name' => 'status',
            'type' => 'enum',
            'enum' => ['a', 'b', 'c'],
        ]);

        $classes = $result['classes'];

        $this->assertCount(4, $classes);

        $valid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'valid',
        ));
        $invalid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'invalid',
        ));

        $this->assertCount(3, $valid);
        $this->assertCount(1, $invalid);

        $validRepresentatives = array_map(
            static fn (array $class): mixed => $class['representative'],
            $valid,
        );
        $this->assertSame(['a', 'b', 'c'], $validRepresentatives);
    }

    public function testInvalidRepresentativeIsProvenAbsentFromMembersViaInArray(): void
    {
        $members = ['a', 'b', 'c'];

        $result = $this->deriver->derive([
            'name' => 'status',
            'type' => 'enum',
            'enum' => $members,
        ]);

        $invalid = array_values(array_filter(
            $result['classes'],
            static fn (array $class): bool => $class['kind'] === 'invalid',
        ));

        $this->assertCount(1, $invalid);
        $this->assertFalse(in_array($invalid[0]['representative'], $members, true));
    }

    public function testDuplicateMembersCollapseToTwoValidPlusOneInvalid(): void
    {
        $result = $this->deriver->derive([
            'name' => 'flag',
            'type' => 'enum',
            'enum' => ['a', 'a', 'b'],
        ]);

        $classes = $result['classes'];

        $this->assertCount(3, $classes);

        $valid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'valid',
        ));
        $invalid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'invalid',
        ));

        $this->assertCount(2, $valid);
        $this->assertCount(1, $invalid);

        $validRepresentatives = array_map(
            static fn (array $class): mixed => $class['representative'],
            $valid,
        );
        $this->assertSame(['a', 'b'], $validRepresentatives);
    }

    public function testEmptyEnumThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'name' => 'status',
            'type' => 'enum',
            'enum' => [],
        ]);
    }

    public function testMissingEnumKeyThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->deriver->derive([
            'name' => 'status',
            'type' => 'enum',
        ]);
    }

    public function testSentinelRegeneratesWhenSeedCollidesWithAMember(): void
    {
        // Anti-scaffold: feed members the test seed could collide with, plus a
        // distinct integer member, and prove the sentinel still lands outside.
        $members = ['__not_a_member__', '__not_a_member__x', 7];

        $result = $this->deriver->derive([
            'name' => 'token',
            'type' => 'enum',
            'enum' => $members,
        ]);

        $classes = $result['classes'];

        $valid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'valid',
        ));
        $invalid = array_values(array_filter(
            $classes,
            static fn (array $class): bool => $class['kind'] === 'invalid',
        ));

        $this->assertCount(3, $valid);
        $this->assertCount(1, $invalid);
        $this->assertFalse(in_array($invalid[0]['representative'], $members, true));
        $this->assertIsString($invalid[0]['representative']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $spec = [
            'name' => 'status',
            'type' => 'enum',
            'enum' => ['a', 'b', 'c'],
        ];

        $first = $this->deriver->derive($spec);
        $second = $this->deriver->derive($spec);

        $this->assertSame($first, $second);
    }
}
