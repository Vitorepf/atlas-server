<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverity;
use Tests\TestCase;

final class AaeosBlockerSeverityTest extends TestCase
{
    public function test_of_normalizes_case_and_rejects_non_string(): void
    {
        self::assertSame('high', AaeosBlockerSeverity::of(['severity' => ' HIGH ']));
        self::assertSame('', AaeosBlockerSeverity::of(['severity' => 1]));
        self::assertSame('', AaeosBlockerSeverity::of('not-an-array'));
    }

    public function test_is_decisive_for_high_and_critical_only(): void
    {
        self::assertTrue(AaeosBlockerSeverity::isDecisive(AaeosBlockerSeverity::HIGH));
        self::assertTrue(AaeosBlockerSeverity::isDecisive(AaeosBlockerSeverity::CRITICAL));
        self::assertFalse(AaeosBlockerSeverity::isDecisive(AaeosBlockerSeverity::MEDIUM));
        self::assertFalse(AaeosBlockerSeverity::isDecisive(''));
    }
}
