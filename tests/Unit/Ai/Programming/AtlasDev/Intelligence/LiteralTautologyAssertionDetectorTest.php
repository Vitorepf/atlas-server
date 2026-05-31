<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\LiteralTautologyAssertionDetector;
use PHPUnit\Framework\TestCase;

final class LiteralTautologyAssertionDetectorTest extends TestCase
{
    private LiteralTautologyAssertionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LiteralTautologyAssertionDetector;
    }

    public function test_assert_true_true_is_counted(): void
    {
        $result = $this->detector->detect('$this->assertTrue(true);');

        $this->assertSame(1, $result['tautology_count']);
        $this->assertSame(['$this->assertTrue(true)'], $result['matches']);
    }

    public function test_assert_false_false_is_counted(): void
    {
        $result = $this->detector->detect('$this->assertFalse(false);');

        $this->assertSame(1, $result['tautology_count']);
        $this->assertSame(['$this->assertFalse(false)'], $result['matches']);
    }

    public function test_identical_numeric_literal_assert_same_is_counted(): void
    {
        $result = $this->detector->detect('$this->assertSame(1, 1);');

        $this->assertSame(1, $result['tautology_count']);
        $this->assertSame(['$this->assertSame(1, 1)'], $result['matches']);
    }

    public function test_identical_quoted_string_assert_equals_is_counted(): void
    {
        $result = $this->detector->detect('$this->assertEquals(\'ok\', \'ok\');');

        $this->assertSame(1, $result['tautology_count']);
        $this->assertStringContainsString('assertEquals', $result['matches'][0]);
    }

    public function test_assert_true_false_is_not_counted(): void
    {
        $result = $this->detector->detect('$this->assertTrue(false);');

        $this->assertSame(0, $result['tautology_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_different_numeric_literals_assert_same_is_not_counted(): void
    {
        $result = $this->detector->detect('$this->assertSame(1, 2);');

        $this->assertSame(0, $result['tautology_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_variable_self_identical_assert_same_is_not_counted(): void
    {
        $result = $this->detector->detect('$this->assertSame($x, $x);');

        $this->assertSame(0, $result['tautology_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_multiple_literal_tautologies_are_counted(): void
    {
        $body = <<<'PHP'
            $this->assertTrue(true);
            $this->assertFalse(false);
            $this->assertSame(42, 42);
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame(3, $result['tautology_count']);
        $this->assertCount(3, $result['matches']);
    }

    public function test_empty_body_returns_zero_matches(): void
    {
        $result = $this->detector->detect('');

        $this->assertSame(0, $result['tautology_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_result_contains_required_schema_keys(): void
    {
        $result = $this->detector->detect('$this->assertTrue(true);');

        $this->assertArrayHasKey('tautology_count', $result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_detector_exposes_only_detect_method(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(LiteralTautologyAssertionDetector::class))->getMethods(),
        );

        $this->assertSame(['detect'], $methods);
    }
}
