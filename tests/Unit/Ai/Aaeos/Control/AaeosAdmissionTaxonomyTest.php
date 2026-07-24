<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionPolicy;
use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosDifficultyLevel;
use PHPUnit\Framework\TestCase;

final class AaeosAdmissionTaxonomyTest extends TestCase
{
    public function test_invalid_mode_is_a_repairable_technical_failure_not_a_sovereign_halt(): void
    {
        $pack = (new AaeosAdmissionPolicy)->admit(
            ['irreversible' => false, 'business_ambiguous' => false],
            ['level' => AaeosDifficultyLevel::L1],
            ['mode' => 'not_a_mode'],
        );

        $this->assertSame(AaeosAdmissionVerdict::REPAIR_REQUIRED, $pack['verdict']);
        $this->assertFalse($pack['allows_execution']);
        $this->assertContains('invalid_mode', $pack['reasons']);
        $this->assertNotSame(AaeosAdmissionVerdict::HALT_SOVEREIGN, $pack['verdict']);
    }
}
