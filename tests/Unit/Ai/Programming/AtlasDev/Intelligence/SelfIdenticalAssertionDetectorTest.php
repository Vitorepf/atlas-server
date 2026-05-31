<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\SelfIdenticalAssertionDetector;
use PHPUnit\Framework\TestCase;

final class SelfIdenticalAssertionDetectorTest extends TestCase
{
    private SelfIdenticalAssertionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new SelfIdenticalAssertionDetector;
    }

    public function test_two_distinct_self_identical_assertions_are_counted(): void
    {
        $body = <<<'PHP'
            $this->assertSame($a, $a);
            $this->assertEquals($foo, $foo);
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame(2, $result['self_identical_count']);
        $this->assertCount(2, $result['matches']);
        $this->assertStringContainsString('assertSame($a, $a)', $result['matches'][0]);
        $this->assertStringContainsString('assertEquals($foo, $foo)', $result['matches'][1]);
    }

    public function test_differing_assert_same_arguments_are_not_counted(): void
    {
        $body = '$this->assertSame($a, $b);';

        $result = $this->detector->detect($body);

        $this->assertSame(0, $result['self_identical_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_property_access_self_identical_assertion_is_counted(): void
    {
        $body = '$this->assertSame($obj->id, $obj->id);';

        $result = $this->detector->detect($body);

        $this->assertSame(1, $result['self_identical_count']);
        $this->assertSame(['$this->assertSame($obj->id, $obj->id)'], $result['matches']);
    }

    public function test_single_arg_assert_true_is_not_counted(): void
    {
        $body = '$this->assertTrue($x);';

        $result = $this->detector->detect($body);

        $this->assertSame(0, $result['self_identical_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_message_third_argument_is_ignored_when_comparing_first_two(): void
    {
        $body = <<<'PHP'
            $this->assertSame($x, $x, 'values should match');
            $this->assertSame($a, $b, 'different values');
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame(1, $result['self_identical_count']);
        $this->assertCount(1, $result['matches']);
        $this->assertStringContainsString('assertSame($x, $x', $result['matches'][0]);
        $this->assertStringContainsString('values should match', $result['matches'][0]);
    }

    public function test_balanced_comma_split_handles_nested_calls(): void
    {
        $body = '$this->assertEquals($this->build(1, 2), $this->build(1, 2));';

        $result = $this->detector->detect($body);

        $this->assertSame(1, $result['self_identical_count']);
        $this->assertCount(1, $result['matches']);
    }

    public function test_empty_body_returns_zero_matches(): void
    {
        $result = $this->detector->detect('');

        $this->assertSame(0, $result['self_identical_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_result_contains_required_schema_keys(): void
    {
        $result = $this->detector->detect('$this->assertSame($x, $x);');

        $this->assertArrayHasKey('self_identical_count', $result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_detector_exposes_only_detect_method(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(SelfIdenticalAssertionDetector::class))->getMethods(),
        );

        $this->assertSame(['detect'], $methods);
    }
}
