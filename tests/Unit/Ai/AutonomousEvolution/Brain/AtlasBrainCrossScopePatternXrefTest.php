<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCrossScopePatternXref;
use Tests\TestCase;

final class AtlasBrainCrossScopePatternXrefTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_returns_empty_with_single_scope(): void
    {
        $r = (new AtlasBrainCrossScopePatternXref)->xref(['scopeA' => $this->tail(['compound', 'compound'])]);
        self::assertSame([], $r['transferable']);
    }

    public function test_finds_hint_common_to_two_scopes(): void
    {
        $r = (new AtlasBrainCrossScopePatternXref)->xref([
            'scopeA' => $this->tail(['compound', 'compound', 'harvest_frontier']),
            'scopeB' => $this->tail(['compound', 'compound', 'gate_regression', 'gate_regression']),
        ]);
        $hints = array_column($r['transferable'], 'hint');
        self::assertContains('compound', $hints);
        self::assertNotContains('harvest_frontier', $hints);
    }

    public function test_below_min_per_scope_count_filtered(): void
    {
        $r = (new AtlasBrainCrossScopePatternXref)->xref([
            'scopeA' => $this->tail(['compound', 'harvest_frontier', 'harvest_frontier']),
            'scopeB' => $this->tail(['compound', 'compound']),
        ]);
        $hints = array_column($r['transferable'], 'hint');
        // compound: A=1 (below min 2), B=2 → not transferable
        self::assertNotContains('compound', $hints);
    }

    public function test_sorted_by_total_descending(): void
    {
        $r = (new AtlasBrainCrossScopePatternXref)->xref([
            'A' => $this->tail(['compound', 'compound', 'use_drafted_candidate', 'use_drafted_candidate', 'use_drafted_candidate']),
            'B' => $this->tail(['compound', 'compound', 'use_drafted_candidate', 'use_drafted_candidate', 'use_drafted_candidate', 'use_drafted_candidate']),
        ]);
        self::assertSame('use_drafted_candidate', $r['transferable'][0]['hint']);
        self::assertSame('compound', $r['transferable'][1]['hint']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCrossScopePatternXref.php',
                true
            )
        );
    }
}
