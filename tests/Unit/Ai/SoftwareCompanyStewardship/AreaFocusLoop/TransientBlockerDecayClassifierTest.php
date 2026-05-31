<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TransientBlockerDecayClassifier;
use PHPUnit\Framework\TestCase;

final class TransientBlockerDecayClassifierTest extends TestCase
{
    private TransientBlockerDecayClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new TransientBlockerDecayClassifier();
    }

    public function testSingleRecurrenceWithinBudgetIsTransient(): void
    {
        $result = $this->classifier->classify(1, 3);

        $this->assertSame('transient', $result['classification']);
        $this->assertFalse($result['decayed']);
        $this->assertSame(1, $result['recurrences']);
        $this->assertSame(3, $result['budget']);
        $this->assertSame(2, $result['remaining']);
    }

    public function testRecurrencesAtBudgetLimitIsStillTransient(): void
    {
        $result = $this->classifier->classify(3, 3);

        $this->assertSame('transient', $result['classification']);
        $this->assertFalse($result['decayed']);
        $this->assertSame(3, $result['recurrences']);
        $this->assertSame(3, $result['budget']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testRecurrencesExceedingBudgetDecaysToPermanent(): void
    {
        $result = $this->classifier->classify(4, 3);

        $this->assertSame('decayed_permanent', $result['classification']);
        $this->assertTrue($result['decayed']);
        $this->assertSame(4, $result['recurrences']);
        $this->assertSame(3, $result['budget']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testNonPositiveBudgetClampedToOne(): void
    {
        $result = $this->classifier->classify(2, 0);

        $this->assertSame('decayed_permanent', $result['classification']);
        $this->assertTrue($result['decayed']);
        $this->assertSame(2, $result['recurrences']);
        $this->assertSame(1, $result['budget']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testNegativeRecurrencesNormalizedToZero(): void
    {
        $result = $this->classifier->classify(-5, 3);

        $this->assertSame('transient', $result['classification']);
        $this->assertFalse($result['decayed']);
        $this->assertSame(0, $result['recurrences']);
        $this->assertSame(3, $result['budget']);
        $this->assertSame(3, $result['remaining']);
    }

    public function testResultContainsAllRequiredKeys(): void
    {
        $result = $this->classifier->classify(1, 3);

        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('decayed', $result);
        $this->assertArrayHasKey('recurrences', $result);
        $this->assertArrayHasKey('budget', $result);
        $this->assertArrayHasKey('remaining', $result);
    }
}
