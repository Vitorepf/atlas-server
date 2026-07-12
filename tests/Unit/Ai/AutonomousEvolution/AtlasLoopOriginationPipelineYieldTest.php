<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldEwma;
use PHPUnit\Framework\TestCase;

final class AtlasLoopOriginationPipelineYieldTest extends TestCase
{
    public function test_yield_aware_pick_prefers_higher_proven_yield_without_demoting_unknown_paths(): void
    {
        $valid = [
            ['Wire low-yield candidate', 'app/low.php', 'frontier-harvest'],
            ['Wire unknown candidate', 'app/unknown.php', 'pattern-design'],
            ['Wire high-yield candidate', 'app/high.php', 'compounding'],
        ];

        $picked = AtlasLoopOriginationPipeline::yieldAwarePick($valid, [
            'frontier-harvest' => ['ewma' => 0.2, 'samples' => 20],
            'compounding' => ['ewma' => 0.8, 'samples' => 20],
        ], enabled: true);

        self::assertSame(['Wire unknown candidate', 'app/unknown.php', 'pattern-design'], $picked);
    }

    public function test_yield_aware_pick_is_byte_identical_when_disabled_or_empty(): void
    {
        $valid = [
            ['Wire A', 'app/a.php', 'frontier-harvest'],
            ['Wire B', 'app/b.php', 'compounding'],
        ];

        self::assertSame($valid[0], AtlasLoopOriginationPipeline::yieldAwarePick($valid, [
            'compounding' => ['ewma' => 0.9, 'samples' => 20],
        ], enabled: false));
        self::assertSame($valid[0], AtlasLoopOriginationPipeline::yieldAwarePick($valid, [], enabled: true));
    }

    public function test_path_yield_ewma_uses_proven_real_not_raw_accepted(): void
    {
        $report = (new AtlasBrainPathYieldEwma)->compute([
            ['action_hint' => 'harvest_frontier', 'result_kind' => 'accepted', 'proven_real' => false],
            ['action_hint' => 'harvest_frontier', 'result_kind' => 'accepted', 'proven_real' => true],
            ['action_hint' => 'compound', 'result_kind' => 'accepted'],
        ], new AtlasBrainHintToPathTranslator, 0.5);

        self::assertSame(0.5, $report['by_path']['frontier-harvest']['ewma']);
        self::assertArrayNotHasKey('compounding', $report['by_path']);
    }
}
