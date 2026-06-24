<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictDetector;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictPolicy;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictResolver;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCycleConflictResolverTest extends TestCase
{
    public function test_divergent_bytes_refuses_both_cycles_with_r_div_receipt(): void
    {
        $report = (new AtlasLoopCycleConflictDetector)->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [10, 20]]]),
            $this->proposal('cycle-b', [['path' => 'app/Foo.php', 'content_hash' => 'bbb', 'byte_range' => [15, 25]]]),
        );

        $decision = (new AtlasLoopCycleConflictResolver)->resolve($report);

        $this->assertSame(AtlasLoopCycleConflictPolicy::DECISION_REFUSE, $decision->decision);
        $this->assertSame(['cycle-a', 'cycle-b'], $decision->blockedCycles);
        $this->assertSame(AtlasLoopCycleConflictPolicy::CLAUSE_DIVERGENT, $decision->receipts[0]['policy_clause']);
    }

    public function test_identical_bytes_auto_merges_only_with_identical_proof_and_r_ident_receipt(): void
    {
        $report = (new AtlasLoopCycleConflictDetector)->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'same', 'byte_range' => [1, 5]]]),
            $this->proposal('cycle-b', [['path' => 'app/Foo.php', 'content_hash' => 'same', 'byte_range' => [100, 120]]]),
        );

        $decision = (new AtlasLoopCycleConflictResolver)->resolve($report);

        $this->assertSame(AtlasLoopCycleConflictPolicy::DECISION_AUTO_MERGE_IDENTICAL, $decision->decision);
        $this->assertSame([], $decision->blockedCycles);
        $this->assertSame(AtlasLoopCycleConflictPolicy::CLAUSE_IDENTICAL, $decision->receipts[0]['policy_clause']);
        $this->assertSame('identical-bytes', $decision->receipts[0]['overlap_mode']);
    }

    public function test_disjoint_hunks_stays_pending_operator_with_r_stitch_receipt_and_no_winner(): void
    {
        $report = (new AtlasLoopCycleConflictDetector)->detect(
            $this->proposal('cycle-a', [['path' => 'app/Foo.php', 'content_hash' => 'aaa', 'byte_range' => [1, 5]]]),
            $this->proposal('cycle-b', [['path' => 'app/Foo.php', 'content_hash' => 'bbb', 'byte_range' => [100, 120]]]),
        );

        $decision = (new AtlasLoopCycleConflictResolver)->resolve($report);

        $this->assertSame(AtlasLoopCycleConflictPolicy::DECISION_STITCH_PENDING_OPERATOR, $decision->decision);
        $this->assertSame([], $decision->blockedCycles);
        $this->assertSame(AtlasLoopCycleConflictPolicy::CLAUSE_STITCH, $decision->receipts[0]['policy_clause']);

        $json = json_encode($decision, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('winner', $json);
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
