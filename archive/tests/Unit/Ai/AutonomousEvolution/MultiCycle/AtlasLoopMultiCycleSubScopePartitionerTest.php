<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\MultiCycle;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleSubScopePartitioner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopMultiCycleSubScopePartitionerTest extends TestCase
{
    public function test_partition_returns_disjoint_subscopes_whose_union_matches_the_input(): void
    {
        $scopeFiles = array_map(
            static fn (int $index): string => sprintf('app/Services/Example/File%02d.php', $index),
            range(1, 20),
        );

        $partitions = (new AtlasLoopMultiCycleSubScopePartitioner)->partition($scopeFiles, 4, 'salt-a');

        $this->assertCount(4, $partitions);

        $union = [];
        foreach ($partitions as $partition) {
            $this->assertArrayHasKey('cycle_id', $partition);
            $this->assertArrayHasKey('files', $partition);
            foreach ($partition['files'] as $path) {
                $this->assertArrayNotHasKey($path, $union);
                $union[$path] = true;
            }
        }

        $expected = $scopeFiles;
        sort($expected, SORT_STRING);
        $actual = array_keys($union);
        sort($actual, SORT_STRING);

        $this->assertSame($expected, $actual);
    }

    public function test_partition_is_deterministic_for_the_same_inputs(): void
    {
        $scopeFiles = [
            'app/A.php',
            'app/B.php',
            'app/C.php',
            'app/D.php',
            'app/E.php',
        ];

        $partitioner = new AtlasLoopMultiCycleSubScopePartitioner;
        $first = $partitioner->partition($scopeFiles, 3, 'stable-salt');
        $second = $partitioner->partition($scopeFiles, 3, 'stable-salt');

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function test_partition_rejects_invalid_cycle_count_and_forbidden_paths(): void
    {
        $partitioner = new AtlasLoopMultiCycleSubScopePartitioner(['app/Forbidden/*']);

        $this->expectException(InvalidArgumentException::class);
        $partitioner->partition(['app/Allowed/Foo.php'], 0, 'salt-a');
    }

    public function test_partition_rejects_scope_files_matching_forbidden_globs(): void
    {
        $partitioner = new AtlasLoopMultiCycleSubScopePartitioner(['app/Forbidden/*']);

        $this->expectException(InvalidArgumentException::class);
        $partitioner->partition(['app/Forbidden/Guard.php'], 1, 'salt-a');
    }
}
