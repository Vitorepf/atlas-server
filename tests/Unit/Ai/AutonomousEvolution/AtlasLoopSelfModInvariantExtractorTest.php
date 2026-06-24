<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * @invariant class_one: $this !== null
 * @invariant class_two: count($this->items) >= 0
 */
final class InvariantFixtureClassLevel
{
}

final class InvariantFixtureEmpty
{
}

final class InvariantFixtureMethodLevel
{
    /**
     * @invariant method_one: $value > 0
     */
    public function run(int $value): void
    {
    }
}

/**
 * @invariant broken missing colon
 * @invariant also_broken:
 */
final class InvariantFixtureMalformed
{
}

final class AtlasLoopSelfModInvariantExtractorTest extends TestCase
{
    public function test_class_level_invariants_return_two_records_with_correct_fields(): void
    {
        $records = (new AtlasLoopSelfModInvariantExtractor)->extract([InvariantFixtureClassLevel::class]);

        $this->assertCount(2, $records[InvariantFixtureClassLevel::class]);
        $this->assertSame('class_one', $records[InvariantFixtureClassLevel::class][0]['invariant_id']);
        $this->assertSame('$this !== null', $records[InvariantFixtureClassLevel::class][0]['expression']);
        $this->assertSame('class', $records[InvariantFixtureClassLevel::class][0]['applies_to']);
        $this->assertSame('class_two', $records[InvariantFixtureClassLevel::class][1]['invariant_id']);
    }

    public function test_method_level_invariant_returns_correct_source_line_and_method_scope(): void
    {
        $records = (new AtlasLoopSelfModInvariantExtractor)->extract([InvariantFixtureMethodLevel::class]);
        $method = new ReflectionMethod(InvariantFixtureMethodLevel::class, 'run');
        $docComment = (string) $method->getDocComment();
        $expectedLine = $method->getStartLine() - count(preg_split("/\r?\n/", $docComment) ?: []) + 2;

        $this->assertSame('method', $records[InvariantFixtureMethodLevel::class][0]['applies_to']);
        $this->assertSame($expectedLine, $records[InvariantFixtureMethodLevel::class][0]['source_line']);
    }

    public function test_malformed_invariant_line_records_parse_error_entry(): void
    {
        $records = (new AtlasLoopSelfModInvariantExtractor)->extract([InvariantFixtureMalformed::class]);

        $this->assertTrue($records[InvariantFixtureMalformed::class][0]['parse_error']);
        $this->assertSame('__parse_error__', $records[InvariantFixtureMalformed::class][0]['invariant_id']);
    }

    public function test_class_without_invariants_returns_empty_array(): void
    {
        $records = (new AtlasLoopSelfModInvariantExtractor)->extract([InvariantFixtureEmpty::class]);

        $this->assertSame([], $records[InvariantFixtureEmpty::class]);
    }
}
