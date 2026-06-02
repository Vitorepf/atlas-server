<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\FactPairPolarityContradictionDetector;
use Tests\TestCase;

final class FactPairPolarityContradictionDetectorTest extends TestCase
{
    private FactPairPolarityContradictionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FactPairPolarityContradictionDetector();
    }

    public function testDifferentSubjectIsUnrelated(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'service_a', 'predicate' => 'status', 'negated' => false, 'value' => 'up'],
            ['subject' => 'service_b', 'predicate' => 'status', 'negated' => true, 'value' => 'down'],
        );

        $this->assertFalse($result['contradicts']);
        $this->assertSame('unrelated', $result['kind']);
    }

    public function testDifferentPredicateIsUnrelated(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'service_a', 'predicate' => 'status', 'negated' => false, 'value' => 'up'],
            ['subject' => 'service_a', 'predicate' => 'region', 'negated' => false, 'value' => 'up'],
        );

        $this->assertFalse($result['contradicts']);
        $this->assertSame('unrelated', $result['kind']);
    }

    public function testSameKeyOppositeNegationIsHardNegationContradiction(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'deploy', 'predicate' => 'succeeded', 'negated' => false, 'value' => null],
            ['subject' => 'deploy', 'predicate' => 'succeeded', 'negated' => true, 'value' => null],
        );

        $this->assertTrue($result['contradicts']);
        $this->assertSame('hard_negation_contradiction', $result['kind']);
    }

    public function testSameKeySameNegationConflictingNonNullValuesIsValueConflict(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'node', 'predicate' => 'replicas', 'negated' => false, 'value' => 3],
            ['subject' => 'node', 'predicate' => 'replicas', 'negated' => false, 'value' => 5],
        );

        $this->assertTrue($result['contradicts']);
        $this->assertSame('value_conflict', $result['kind']);
    }

    public function testMatchingLooselyEqualValuesIsNone(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'node', 'predicate' => 'replicas', 'negated' => false, 'value' => 3],
            ['subject' => 'node', 'predicate' => 'replicas', 'negated' => false, 'value' => '3'],
        );

        $this->assertFalse($result['contradicts']);
        $this->assertSame('none', $result['kind']);
    }

    public function testCaseAndWhitespaceInsensitiveSubjectMatchStillContradicts(): void
    {
        $result = $this->detector->detect(
            ['subject' => '  Order Service  ', 'predicate' => '  Healthy  ', 'negated' => false, 'value' => null],
            ['subject' => 'order service', 'predicate' => 'healthy', 'negated' => true, 'value' => null],
        );

        $this->assertTrue($result['contradicts']);
        $this->assertSame('hard_negation_contradiction', $result['kind']);
    }

    public function testNullValueOnEitherSideStaysNoneUnderSamePolarity(): void
    {
        $result = $this->detector->detect(
            ['subject' => 'cache', 'predicate' => 'ttl', 'negated' => false, 'value' => 60],
            ['subject' => 'cache', 'predicate' => 'ttl', 'negated' => false, 'value' => null],
        );

        $this->assertFalse($result['contradicts']);
        $this->assertSame('none', $result['kind']);
    }
}
