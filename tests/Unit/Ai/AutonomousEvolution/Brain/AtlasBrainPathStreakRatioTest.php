<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStreakRatio;
use Tests\TestCase;

final class AtlasBrainPathStreakRatioTest extends TestCase
{
    public function test_pure_win(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 5, 'longest_refused' => 0]]);
        self::assertSame('pure_win', $r['by_path']['a']['label']);
    }

    public function test_pure_loss(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 0, 'longest_refused' => 3]]);
        self::assertSame('pure_loss', $r['by_path']['a']['label']);
        self::assertSame(0.0, $r['by_path']['a']['ratio']);
    }

    public function test_accept_dominant(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 6, 'longest_refused' => 2]]);
        self::assertSame('accept_dominant', $r['by_path']['a']['label']);
        self::assertSame(3.0, $r['by_path']['a']['ratio']);
    }

    public function test_balanced(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 3, 'longest_refused' => 4]]);
        self::assertSame('balanced', $r['by_path']['a']['label']);
    }

    public function test_refuse_dominant(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 1, 'longest_refused' => 5]]);
        self::assertSame('refuse_dominant', $r['by_path']['a']['label']);
    }

    public function test_no_data(): void
    {
        $r = (new AtlasBrainPathStreakRatio)->compute(['a' => ['longest_accepted' => 0, 'longest_refused' => 0]]);
        self::assertSame('no_data', $r['by_path']['a']['label']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathStreakRatio.php',
                true
            )
        );
    }
}
