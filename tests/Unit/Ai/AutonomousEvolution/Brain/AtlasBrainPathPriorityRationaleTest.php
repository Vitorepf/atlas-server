<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathPriorityRationale;
use Tests\TestCase;

final class AtlasBrainPathPriorityRationaleTest extends TestCase
{
    public function test_formats_top_n(): void
    {
        $r = (new AtlasBrainPathPriorityRationale)->format([
            ['path' => 'compounding', 'score' => 90, 'agreement' => 'oscillation_pin', 'starvation' => 2],
            ['path' => 'frontier-harvest', 'score' => 50, 'agreement' => 'signals_disagree', 'starvation' => 0],
        ], 5);
        self::assertCount(2, $r['lines']);
        self::assertStringContainsString('compounding', $r['lines'][0]);
        self::assertStringContainsString('oscillation pin', $r['lines'][0]);
    }

    public function test_respects_top_cap(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['path' => "p$i", 'score' => 10, 'agreement' => 'signals_disagree', 'starvation' => 0];
        }
        $r = (new AtlasBrainPathPriorityRationale)->format($rows, 3);
        self::assertCount(3, $r['lines']);
    }

    public function test_unknown_agreement_passes_through(): void
    {
        $r = (new AtlasBrainPathPriorityRationale)->format([
            ['path' => 'a', 'score' => 5, 'agreement' => 'custom_label', 'starvation' => 1],
        ]);
        self::assertStringContainsString('custom_label', $r['lines'][0]);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathPriorityRationale.php',
                true
            )
        );
    }
}
