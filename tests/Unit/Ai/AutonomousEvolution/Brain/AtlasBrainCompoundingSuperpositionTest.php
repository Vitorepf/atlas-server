<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingSuperposition;
use Tests\TestCase;

final class AtlasBrainCompoundingSuperpositionTest extends TestCase
{
    public function test_fuses_four_layers(): void
    {
        $r = (new AtlasBrainCompoundingSuperposition)->fuse(
            ['compounding' => ['trend' => 'improving']],
            ['compounding' => ['label' => 'accelerating']],
            ['compounding' => ['ewma' => 0.7]],
            ['compounding' => ['label' => 'accept_dominant']],
        );
        self::assertSame('improving', $r['by_path']['compounding']['momentum']);
        self::assertSame('accelerating', $r['by_path']['compounding']['velocity']);
        self::assertSame(0.7, $r['by_path']['compounding']['ewma']);
        self::assertSame('accept_dominant', $r['by_path']['compounding']['streak']);
    }

    public function test_missing_layers_default_to_unknown(): void
    {
        $r = (new AtlasBrainCompoundingSuperposition)->fuse(
            ['compounding' => ['trend' => 'flat']],
            [],
            [],
            [],
        );
        self::assertSame('unknown', $r['by_path']['compounding']['velocity']);
        self::assertSame(0.0, $r['by_path']['compounding']['ewma']);
        self::assertSame('no_data', $r['by_path']['compounding']['streak']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCompoundingSuperposition.php',
                true
            )
        );
    }
}
