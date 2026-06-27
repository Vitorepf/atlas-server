<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainContrarianRequirement;
use Tests\TestCase;

final class AtlasBrainContrarianRequirementTest extends TestCase
{
    public function test_unanimous_support_fails(): void
    {
        $r = (new AtlasBrainContrarianRequirement)->check(['a' => true, 'b' => true]);
        self::assertFalse($r['ok']);
        self::assertSame('unanimous_no_contrarian', $r['reason']);
    }

    public function test_unanimous_refute_fails(): void
    {
        $r = (new AtlasBrainContrarianRequirement)->check(['a' => false, 'b' => false]);
        self::assertFalse($r['ok']);
    }

    public function test_mixed_passes(): void
    {
        $r = (new AtlasBrainContrarianRequirement)->check(['a' => true, 'b' => false, 'c' => true]);
        self::assertTrue($r['ok']);
        self::assertSame('has_contrarian', $r['reason']);
    }

    public function test_empty_fails(): void
    {
        $r = (new AtlasBrainContrarianRequirement)->check([]);
        self::assertFalse($r['ok']);
        self::assertSame('no_votes', $r['reason']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainContrarianRequirement.php',
                true
            )
        );
    }
}
