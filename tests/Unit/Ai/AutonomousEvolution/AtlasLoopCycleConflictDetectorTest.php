<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictDetector;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCycleConflictDetectorTest extends TestCase
{
    public function test_it_reports_divergent_byte_conflicts_without_score_or_winner_fields(): void
    {
        $report = (new AtlasLoopCycleConflictDetector)->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [10, 20]]]),
            $this->proposal('cycle-b', [['path' => 'app/Foo.php', 'content_hash' => 'bbb', 'byte_range' => [15, 25]]]),
        );

        $this->assertTrue($report->hasConflict());
        $this->assertSame(['app/Foo.php'], $report->overlappingPaths);
        $this->assertSame('divergent-bytes', $report->perFile[0]['mode']);

        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('score', $json);
        $this->assertStringNotContainsString('winner', $json);
    }

    public function test_it_reports_no_conflict_for_disjoint_files(): void
    {
        $report = (new AtlasLoopCycleConflictDetector)->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [10, 20]]]),
            $this->proposal('cycle-b', [['path' => 'app/Bar.php', 'content_hash' => 'bbb', 'byte_range' => [15, 25]]]),
        );

        $this->assertFalse($report->hasConflict());
        $this->assertSame([], $report->overlappingPaths);
        $this->assertSame([], $report->perFile);
    }

    public function test_it_reuses_the_mf12_overlap_predicate_as_sole_overlap_truth(): void
    {
        $calls = 0;
        $detector = new AtlasLoopCycleConflictDetector(function (array $leftPaths, array $rightPaths) use (&$calls): array {
            $calls++;
            $this->assertSame(['app/Foo.php'], $leftPaths);
            $this->assertSame(['app/Foo.php'], $rightPaths);

            return ['app/Foo.php'];
        });

        $report = $detector->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [1, 5]]]),
            $this->proposal('cycle-b', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [100, 120]]]),
        );

        $this->assertSame(1, $calls);
        $this->assertSame('identical-bytes', $report->perFile[0]['mode']);
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @return array<string,mixed>
     */
    private function proposal(string $cycleId, array $files): array
    {
        return [
            'cycle_id' => $cycleId,
            'branch_sha' => 'sha-'.$cycleId,
            'files' => $files,
        ];
    }
}
