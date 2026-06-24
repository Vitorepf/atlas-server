<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use Tests\TestCase;

final class AtlasLoopV2BlastRadiusCalculatorTest extends TestCase
{
    public function test_zero_changed_files_is_safe_with_zero_score(): void
    {
        $result = $this->calculator()->calculate([], [], []);

        $this->assertSame([
            'schema_version',
            'files_touched',
            'transitive_consumers',
            'critical_path_hits',
            'radius_score',
            'tier',
            'reasons',
        ], array_keys($result));
        $this->assertSame('atlas.ai.loop_v2.blast_radius.v1', $result['schema_version']);
        $this->assertSame(0, $result['files_touched']);
        $this->assertSame(0, $result['transitive_consumers']);
        $this->assertSame(0, $result['radius_score']);
        $this->assertSame('safe', $result['tier']);
    }

    public function test_twenty_five_changed_files_is_wide(): void
    {
        $files = array_map(static fn (int $i): string => 'app/File'.$i.'.php', range(1, 25));

        $result = $this->calculator()->calculate($files, [], []);

        $this->assertSame(25, $result['files_touched']);
        $this->assertSame(50, $result['radius_score']);
        $this->assertSame('wide', $result['tier']);
        $this->assertContains('files_touched_ge_20', $result['reasons']);
    }

    public function test_critical_path_hit_is_critical_independent_of_counts(): void
    {
        $result = $this->calculator()->calculate(
            ['config/atlas.php'],
            [],
            ['app/Services/Ai/AutonomousEvolution/V2/', 'config/atlas.php'],
        );

        $this->assertSame('critical', $result['tier']);
        $this->assertSame(['config/atlas.php'], $result['critical_path_hits']);
        $this->assertSame(17, $result['radius_score']);
    }

    public function test_transitive_bfs_expands_consumers(): void
    {
        $result = $this->calculator()->calculate(
            ['A.php'],
            [
                'A.php' => ['B.php', 'C.php'],
                'B.php' => ['D.php'],
            ],
            [],
        );

        $this->assertSame(3, $result['transitive_consumers']);
        $this->assertSame(5, $result['radius_score']);
        $this->assertSame('safe', $result['tier']);
    }

    public function test_bfs_is_finite_with_cycles(): void
    {
        $result = $this->calculator()->calculate(
            ['A.php'],
            [
                'A.php' => ['B.php'],
                'B.php' => ['C.php'],
                'C.php' => ['A.php'],
            ],
            [],
        );

        $this->assertSame(2, $result['transitive_consumers']);
        $this->assertSame(4, $result['radius_score']);
    }

    public function test_score_saturates_at_one_hundred(): void
    {
        $files = array_map(static fn (int $i): string => 'app/File'.$i.'.php', range(1, 60));
        $result = $this->calculator()->calculate($files, [], []);

        $this->assertSame(100, $result['radius_score']);
        $this->assertSame('wide', $result['tier']);
    }

    private function calculator(): AtlasLoopV2BlastRadiusCalculator
    {
        return new AtlasLoopV2BlastRadiusCalculator;
    }
}
