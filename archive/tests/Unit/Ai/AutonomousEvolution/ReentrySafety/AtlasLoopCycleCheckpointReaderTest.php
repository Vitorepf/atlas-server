<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointReader;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleRecoveryFact;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointWriter;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCycleCheckpointReaderTest extends TestCase
{
    public function test_recovery_state_returns_exact_fact_for_seeded_three_phase_ledger(): void
    {
        $dir = sys_get_temp_dir().'/atlas-reentry-reader-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);

        try {
            $writer = new AtlasLoopCycleCheckpointWriter($dir, ['selected', 'planned', 'executed', 'verified']);
            $writer->recordPhase('cycle-a', 'selected', $this->facts('base-a', null, ['r-1'], ['claim-1']));
            $writer->recordPhase('cycle-a', 'planned', $this->facts('base-b', null, ['r-2'], ['claim-2']));
            $writer->recordPhase('cycle-a', 'executed', $this->facts('base-c', 'merge-c', ['r-3', 'r-4'], ['claim-3']));

            $reader = new AtlasLoopCycleCheckpointReader($dir, ['selected', 'planned', 'executed', 'verified']);
            $fact = $reader->recoveryState('cycle-a');

            $this->assertInstanceOf(AtlasLoopCycleRecoveryFact::class, $fact);
            $this->assertSame('cycle-a', $fact->cycleId);
            $this->assertSame('executed', $fact->lastCompletedPhase);
            $this->assertSame('verified', $fact->nextPhaseToRun);
            $this->assertSame('base-c', $fact->baseCommitSha);
            $this->assertSame('merge-c', $fact->mergedSha);
            $this->assertSame(['r-3', 'r-4'], $fact->emittedReceiptIds);
            $this->assertSame(['claim-3'], $fact->heldTaskClaimIds);
            $this->assertFalse($fact->tornTail);
        } finally {
            $this->cleanup($dir);
        }
    }

    public function test_recovery_state_detects_torn_tail_and_points_to_prior_intact_record(): void
    {
        $dir = sys_get_temp_dir().'/atlas-reentry-reader-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);

        try {
            $writer = new AtlasLoopCycleCheckpointWriter($dir, ['selected', 'planned', 'executed', 'verified']);
            $writer->recordPhase('cycle-b', 'selected', $this->facts('base-a', null, ['r-1'], ['claim-1']));
            $writer->recordPhase('cycle-b', 'planned', $this->facts('base-b', 'merge-b', ['r-2'], ['claim-2']));
            $writer->recordPhase('cycle-b', 'executed', $this->facts('base-c', 'merge-c', ['r-3'], ['claim-3']));

            $path = $dir.'/cycle-b.json';
            $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $state['records'][2]['commit_sha_base'] = 'base-c-corrupted';
            file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $reader = new AtlasLoopCycleCheckpointReader($dir, ['selected', 'planned', 'executed', 'verified']);
            $fact = $reader->recoveryState('cycle-b');

            $this->assertSame('planned', $fact->lastCompletedPhase);
            $this->assertSame('executed', $fact->nextPhaseToRun);
            $this->assertSame('base-b', $fact->baseCommitSha);
            $this->assertSame('merge-b', $fact->mergedSha);
            $this->assertSame(['r-2'], $fact->emittedReceiptIds);
            $this->assertSame(['claim-2'], $fact->heldTaskClaimIds);
            $this->assertTrue($fact->tornTail);
        } finally {
            $this->cleanup($dir);
        }
    }

    public function test_recovery_state_returns_not_started_fact_when_ledger_is_missing(): void
    {
        $dir = sys_get_temp_dir().'/atlas-reentry-reader-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);

        try {
            $reader = new AtlasLoopCycleCheckpointReader($dir, ['selected', 'planned', 'executed']);
            $fact = $reader->recoveryState('cycle-missing');

            $this->assertNull($fact->lastCompletedPhase);
            $this->assertSame('selected', $fact->nextPhaseToRun);
            $this->assertNull($fact->baseCommitSha);
            $this->assertNull($fact->mergedSha);
            $this->assertSame([], $fact->emittedReceiptIds);
            $this->assertSame([], $fact->heldTaskClaimIds);
            $this->assertFalse($fact->tornTail);
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * @return array{commit_sha_base:string,merged_sha:?string,emitted_receipt_ids:list<string>,held_task_claim_ids:list<string>}
     */
    private function facts(
        string $commitShaBase,
        ?string $mergedSha,
        array $emittedReceiptIds,
        array $heldTaskClaimIds,
    ): array {
        return [
            'commit_sha_base' => $commitShaBase,
            'merged_sha' => $mergedSha,
            'emitted_receipt_ids' => $emittedReceiptIds,
            'held_task_claim_ids' => $heldTaskClaimIds,
        ];
    }

    private function cleanup(string $dir): void
    {
        foreach ((array) glob($dir.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($dir);
    }
}
