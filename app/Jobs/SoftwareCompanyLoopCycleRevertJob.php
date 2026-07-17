<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * M08 queued marker for a governed cycle revert.
 *
 * This job deliberately does not run `git revert`: the real executor is not yet
 * implemented. The HTTP surface records the append-only `revert_status=enqueued`
 * receipt before dispatching this job, so the operator sees the honest state and
 * no merge undo is ever fabricated.
 */
final class SoftwareCompanyLoopCycleRevertJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'software_company_loop_reverts';

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<string,mixed>  $mission
     */
    public function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly int $cycleIndex,
        public readonly string $cycleId,
        public readonly string $mergeHash,
        public readonly string $operatorActor,
        public readonly string $reason,
        public readonly string $receiptId,
        public readonly array $mission,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        // Worker gap: M08 only persists and queues the governed request. A future
        // executor must perform git revert and append a follow-up receipt.
    }
}
