<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleIdempotencyGuard;
use Tests\TestCase;

final class AtlasLoopCycleIdempotencyGuardTest extends TestCase
{
    /**
     * @param  array<string,mixed>|null  $checkpoint
     */
    private function reader(?array $checkpoint): object
    {
        return new class($checkpoint)
        {
            public function __construct(private ?array $checkpoint) {}

            public function read(string $cycleId): ?array
            {
                return $this->checkpoint;
            }
        };
    }

    /**
     * @param  list<string>  $reachableShas
     */
    private function gitLogProbe(array $reachableShas): object
    {
        return new class($reachableShas)
        {
            public function __construct(private array $reachable) {}

            public function isReachableFromMain(string $sha): bool
            {
                return in_array($sha, $this->reachable, true);
            }
        };
    }

    private function guard(?array $checkpoint, array $reachableShas = []): AtlasLoopCycleIdempotencyGuard
    {
        return new AtlasLoopCycleIdempotencyGuard($this->reader($checkpoint), $this->gitLogProbe($reachableShas));
    }

    public function test_fresh_when_no_checkpoint_yet(): void
    {
        $verdict = $this->guard(null)->classify('cyc-1', 'merge_to_main', 'base-sha-A');
        $this->assertSame('FRESH', $verdict['verdict']);
        $this->assertNull($verdict['prior_fact']);
    }

    public function test_already_done_merge_when_merged_sha_reachable_from_main(): void
    {
        $verdict = $this->guard(
            ['merged_sha' => 'merged-sha-1', 'base_commit_sha' => 'base-sha-A'],
            reachableShas: ['merged-sha-1'],
        )->classify('cyc-1', 'merge_to_main', 'base-sha-A');

        $this->assertSame('ALREADY_DONE', $verdict['verdict']);
        $this->assertSame('merged-sha-1', $verdict['prior_fact']['merged_sha']);
    }

    public function test_torn_when_merged_sha_present_but_not_reachable(): void
    {
        $verdict = $this->guard(
            ['merged_sha' => 'merged-sha-2', 'base_commit_sha' => 'base-sha-A'],
            reachableShas: [],
        )->classify('cyc-1', 'merge_to_main', 'base-sha-A');

        $this->assertSame('TORN', $verdict['verdict']);
        $this->assertSame('merged-sha-2', $verdict['prior_fact']['merged_sha']);
    }

    public function test_fresh_merge_when_checkpoint_base_differs_from_current_attempt(): void
    {
        $verdict = $this->guard(
            ['merged_sha' => 'merged-sha-3', 'base_commit_sha' => 'base-sha-OLD'],
            reachableShas: ['merged-sha-3'],
        )->classify('cyc-1', 'merge_to_main', 'base-sha-NEW');

        $this->assertSame('FRESH', $verdict['verdict'], 'new base ⇒ FRESH even if prior merged_sha is reachable');
    }

    public function test_fresh_merge_when_real_producer_key_commit_sha_base_differs(): void
    {
        $verdict = $this->guard(
            ['merged_sha' => 'merged-sha-4', 'commit_sha_base' => 'base-sha-OLD'],
            reachableShas: ['merged-sha-4'],
        )->classify('cyc-1', 'merge_to_main', 'base-sha-NEW');

        $this->assertSame('FRESH', $verdict['verdict'], 'real producer key commit_sha_base mismatch ⇒ FRESH');
    }

    public function test_already_done_when_receipt_id_already_emitted(): void
    {
        $verdict = $this->guard(['emitted_receipt_ids' => ['rcpt-A', 'rcpt-B']])
            ->classify('cyc-1', 'emit_receipt', 'rcpt-A');
        $this->assertSame('ALREADY_DONE', $verdict['verdict']);

        $verdict = $this->guard(['emitted_receipt_ids' => ['rcpt-A']])
            ->classify('cyc-1', 'emit_receipt', 'rcpt-NEW');
        $this->assertSame('FRESH', $verdict['verdict']);
    }

    public function test_already_done_when_task_claim_id_already_held(): void
    {
        $verdict = $this->guard(['held_task_claim_ids' => ['claim-X']])
            ->classify('cyc-1', 'claim_task', 'claim-X');
        $this->assertSame('ALREADY_DONE', $verdict['verdict']);

        $verdict = $this->guard(['held_task_claim_ids' => ['claim-X']])
            ->classify('cyc-1', 'claim_task', 'claim-NEW');
        $this->assertSame('FRESH', $verdict['verdict']);
    }
}
