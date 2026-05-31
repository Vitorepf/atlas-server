<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\VacuousSuppressionAssertionDetector;
use PHPUnit\Framework\TestCase;

final class VacuousSuppressionAssertionDetectorTest extends TestCase
{
    private VacuousSuppressionAssertionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new VacuousSuppressionAssertionDetector;
    }

    public function test_whitespace_and_comment_only_body_is_empty(): void
    {
        $body = <<<'PHP'

            // assertSame($a, $b) appears only in a comment.
            /* $this->markTestSkipped('comment only'); */

        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame('empty', $result['verdict']);
        $this->assertSame(0, $result['suppression_count']);
        $this->assertFalse($result['has_other_statements']);
    }

    public function test_two_mark_test_skipped_calls_only_are_suppression_only(): void
    {
        $body = <<<'PHP'
            $this->markTestSkipped('platform unavailable');
            $this->markTestSkipped('git binary required');
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame('suppression_only', $result['verdict']);
        $this->assertSame(2, $result['suppression_count']);
        $this->assertFalse($result['has_other_statements']);
    }

    public function test_assertion_alongside_mark_test_incomplete_has_real_statements(): void
    {
        $body = <<<'PHP'
            $this->markTestIncomplete('FASE 3 not implemented');
            $this->assertSame(1, 2);
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame('has_real_statements', $result['verdict']);
        $this->assertSame(1, $result['suppression_count']);
        $this->assertTrue($result['has_other_statements']);
    }

    public function test_comments_are_not_counted_as_other_statements(): void
    {
        $body = <<<'PHP'
            // only a comment about $this->assertSame($a, $b)
            /* another comment with $this->doWork(); */
            $this->markTestSkipped('skipped');
        PHP;

        $result = $this->detector->detect($body);

        $this->assertSame('suppression_only', $result['verdict']);
        $this->assertSame(1, $result['suppression_count']);
        $this->assertFalse($result['has_other_statements']);
    }

    public function test_single_expect_not_to_perform_assertions_is_suppression_only(): void
    {
        $result = $this->detector->detect('$this->expectNotToPerformAssertions();');

        $this->assertSame('suppression_only', $result['verdict']);
        $this->assertSame(1, $result['suppression_count']);
        $this->assertFalse($result['has_other_statements']);
    }

    public function test_result_contains_required_schema_keys(): void
    {
        $result = $this->detector->detect('$this->markTestSkipped();');

        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('suppression_count', $result);
        $this->assertArrayHasKey('has_other_statements', $result);
    }

    public function test_detector_exposes_only_detect_method(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(VacuousSuppressionAssertionDetector::class))->getMethods(),
        );

        $this->assertSame(['detect'], $methods);
    }
}
