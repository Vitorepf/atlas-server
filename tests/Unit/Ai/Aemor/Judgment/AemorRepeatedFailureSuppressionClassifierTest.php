<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorRepeatedFailureSuppressionClassifier;
use Tests\TestCase;

final class AemorRepeatedFailureSuppressionClassifierTest extends TestCase
{
    private AemorRepeatedFailureSuppressionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new AemorRepeatedFailureSuppressionClassifier();
    }

    public function testNullSignatureShortCircuitsToClearAndIgnoresRepeatCount(): void
    {
        $result = $this->classifier->classify(null, 9);

        self::assertSame('clear', $result['status']);
        self::assertSame(0, $result['repeat_count']);
        self::assertSame([], $result['required_mitigation']);
        self::assertFalse(array_key_exists('failure_signature', $result));
        self::assertSame('atlas.aemor.repeated_failure_suppression.v1', $result['schema_version']);
    }

    public function testSingleOccurrenceStaysClearWithoutMitigation(): void
    {
        $result = $this->classifier->classify('sigA', 1);

        self::assertSame('clear', $result['status']);
        self::assertSame([], $result['required_mitigation']);
        self::assertSame(1, $result['repeat_count']);
        self::assertSame('sigA', $result['failure_signature']);
    }

    public function testTwoOccurrencesEnterWatchBandWithMitigation(): void
    {
        $result = $this->classifier->classify('sigA', 2);

        self::assertSame('watch', $result['status']);
        self::assertSame(
            ['include_negative_knowledge_in_apcr', 'run_targeted_repair_test'],
            $result['required_mitigation'],
        );
    }

    public function testThreeOccurrencesEscalateToBlockedWithTwoMitigations(): void
    {
        $result = $this->classifier->classify('sigA', 3);

        self::assertSame('blocked', $result['status']);
        self::assertSame(2, count($result['required_mitigation']));
    }

    public function testWhitespaceOnlySignatureShortCircuitsToClear(): void
    {
        $result = $this->classifier->classify('  ', 5);

        self::assertSame('clear', $result['status']);
        self::assertSame(0, $result['repeat_count']);
        self::assertFalse(array_key_exists('failure_signature', $result));
    }

    public function testPresentSignatureIsTrimmedAndCountEchoedForHigherCounts(): void
    {
        $result = $this->classifier->classify('  sigB  ', 4);

        self::assertSame('blocked', $result['status']);
        self::assertSame('sigB', $result['failure_signature']);
        self::assertSame(4, $result['repeat_count']);
    }
}
