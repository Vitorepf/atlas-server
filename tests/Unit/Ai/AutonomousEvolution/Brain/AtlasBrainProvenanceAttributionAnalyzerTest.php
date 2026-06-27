<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceAttributionAnalyzer;
use Tests\TestCase;

final class AtlasBrainProvenanceAttributionAnalyzerTest extends TestCase
{
    public function test_attribute_counts_by_source_finding(): void
    {
        $r = (new AtlasBrainProvenanceAttributionAnalyzer)->analyze([
            ['source_finding' => 'frontier_empty'],
            ['source_finding' => 'gate_regression'],
            ['source_finding' => 'frontier_empty'],
            ['source_finding' => ''],
        ]);
        self::assertSame(4, $r['total']);
        // frontier_empty=2 first.
        self::assertSame('frontier_empty', $r['by_finding'][0]['source_finding']);
        self::assertSame(2, $r['by_finding'][0]['count']);
        self::assertSame(50, $r['by_finding'][0]['pct']);
        // gate_regression=1 next; (none)=1 last (alpha tie-break).
        $codes = array_column($r['by_finding'], 'source_finding');
        self::assertContains('(none)', $codes);
    }

    public function test_attribute_empty_input(): void
    {
        $r = (new AtlasBrainProvenanceAttributionAnalyzer)->analyze([]);
        self::assertSame(0, $r['total']);
        self::assertSame([], $r['by_finding']);
    }

    public function test_analyzer_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProvenanceAttributionAnalyzer.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
