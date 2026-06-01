<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverityGate;
use Tests\TestCase;

final class AaeosBlockerSeverityGateTest extends TestCase
{
    private AaeosBlockerSeverityGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new AaeosBlockerSeverityGate();
    }

    public function testOneHighWithSeveralMediumSignalsBlocked(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => 'high', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => 'medium', 'owner' => 'aaeos'],
            ['id' => 'b3', 'severity' => 'medium', 'owner' => 'aaeos'],
            ['id' => 'b4', 'severity' => 'medium', 'owner' => 'aaeos'],
        ]);

        self::assertSame('blocked', $result['signal']);
        self::assertTrue($result['high_count'] >= 1);
        self::assertSame(1, $result['high_count']);
        self::assertSame(3, $result['medium_count']);
    }

    public function testMediumOnlySignalsWarningWithHighCountZero(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => 'medium', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => 'medium', 'owner' => 'aaeos'],
        ]);

        self::assertSame('warning', $result['signal']);
        self::assertSame(2, $result['medium_count']);
        self::assertSame(0, $result['high_count']);
    }

    public function testEmptyListSignalsClearWithBothCountsZero(): void
    {
        $result = $this->gate->assess([]);

        self::assertSame('clear', $result['signal']);
        self::assertSame(0, $result['high_count']);
        self::assertSame(0, $result['medium_count']);
    }

    public function testLowOnlySignalsClear(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => 'low', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => 'low', 'owner' => 'aaeos'],
        ]);

        self::assertSame('clear', $result['signal']);
        self::assertSame(0, $result['high_count']);
        self::assertSame(0, $result['medium_count']);
    }

    public function testMixedCaseHighIsNormalizedAndStillTriggersBlocked(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => 'HIGH', 'owner' => 'aaeos'],
        ]);

        self::assertSame('blocked', $result['signal']);
        self::assertSame(1, $result['high_count']);
        self::assertSame(0, $result['medium_count']);
    }

    public function testHighOverridesMediumEvenWhenMediumAppearsFirst(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => 'medium', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => 'HIGH', 'owner' => 'aaeos'],
            ['id' => 'b3', 'severity' => 'medium', 'owner' => 'aaeos'],
        ]);

        self::assertSame('blocked', $result['signal']);
        self::assertSame(1, $result['high_count']);
        self::assertSame(2, $result['medium_count']);
    }

    public function testWhitespaceAndCaseAreNormalizedWhenTallying(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'severity' => '  High  ', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => "\tMEDIUM\n", 'owner' => 'aaeos'],
            ['id' => 'b3', 'severity' => 'MeDiUm', 'owner' => 'aaeos'],
        ]);

        self::assertSame('blocked', $result['signal']);
        self::assertSame(1, $result['high_count']);
        self::assertSame(2, $result['medium_count']);
    }

    public function testMissingOrBlankSeverityIsIgnoredAndNotCounted(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => '', 'owner' => 'aaeos'],
            ['id' => 'b3', 'severity' => '   ', 'owner' => 'aaeos'],
            ['id' => 'b4', 'severity' => 'medium', 'owner' => 'aaeos'],
        ]);

        self::assertSame('warning', $result['signal']);
        self::assertSame(0, $result['high_count']);
        self::assertSame(1, $result['medium_count']);
    }

    public function testBlankSeverityOnlyStaysClear(): void
    {
        $result = $this->gate->assess([
            ['id' => 'b1', 'owner' => 'aaeos'],
            ['id' => 'b2', 'severity' => '   ', 'owner' => 'aaeos'],
        ]);

        self::assertSame('clear', $result['signal']);
        self::assertSame(0, $result['high_count']);
        self::assertSame(0, $result['medium_count']);
    }
}
