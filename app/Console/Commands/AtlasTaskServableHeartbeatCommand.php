<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServableHeartbeatService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;

final class AtlasTaskServableHeartbeatCommand extends Command
{
    public const HINT_WAIT = 'wait';

    public const HINT_REPLENISH_SOON = 'replenish_soon';

    public const HINT_URGENT = 'urgent';

    protected $signature = 'atlas:task:servable-heartbeat {--json : Print machine-readable JSON}';

    protected $description = 'Servable-heartbeat: reads servability and auto-fires reap → sweep → repair when queue is jammed.';

    public function handle(): int
    {
        $service = app()->bound(AtlasTaskServableHeartbeatService::class)
            ? app(AtlasTaskServableHeartbeatService::class)
            : new AtlasTaskServableHeartbeatService();

        $envelope = $service->tick();

        $replenishFacts = $this->replenishFacts((int) $envelope['servable_now']);
        $envelope['replenish_hint'] = $replenishFacts['hint'];
        $envelope['replenish_facts'] = $replenishFacts['facts'];

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'servable=%d claimable=%d status=%s actions=%s replenish_hint=%s receipt=%s',
                $envelope['servable_now'],
                $envelope['claimable_depth'],
                $envelope['status'],
                implode(',', $envelope['actions_fired']) ?: '-',
                $envelope['replenish_hint'],
                $envelope['receipt_path'],
            ));
        }

        return $envelope['ok'] ? 0 : 1;
    }

    /**
     * Read-only replenish hint: derives wait|replenish_soon|urgent from servable_now, active
     * leases, recoverable backlog and malformed-claimable count. Never mutates the queue — the
     * malformed count is taken from a dry-run sweep inspection only.
     *
     * @return array{hint:string, facts:array<string,mixed>}
     */
    private function replenishFacts(int $servableNow): array
    {
        $activeLeases = 0;
        $recoverableTotal = 0;
        try {
            $healthService = app()->bound(AtlasTaskCoordinationHealthService::class)
                ? app(AtlasTaskCoordinationHealthService::class)
                : new AtlasTaskCoordinationHealthService();
            $snapshot = $healthService->snapshot();
            $activeLeases = (int) ($snapshot['active_leases'] ?? 0);
            $recoverableTotal = (int) ($snapshot['recoverable']['total'] ?? 0);
        } catch (Throwable) {
            // fail-open: facts default to zero, hint falls back to servable_now-only logic.
        }

        $malformedCount = 0;
        try {
            $sweep = AtlasTaskServingStack::orchestrator()->sweepMalformedClaimableTasks(
                limit: 0,
                dryRun: true,
                actor: 'servable_heartbeat_replenish_hint',
            );
            $malformedCount = (int) ($sweep['would_block_count'] ?? 0);
        } catch (Throwable) {
            // fail-open: never let a read-only inspection wedge the heartbeat.
        }

        $hint = self::HINT_WAIT;
        if ($activeLeases > 0) {
            $claimablePerWorker = intdiv($servableNow, $activeLeases);
            $hint = match (true) {
                $servableNow === 0 => self::HINT_URGENT,
                $claimablePerWorker < 2 => self::HINT_URGENT,
                $claimablePerWorker < 5 => self::HINT_REPLENISH_SOON,
                default => self::HINT_WAIT,
            };
        } elseif ($servableNow === 0) {
            $hint = self::HINT_URGENT;
        }

        $reasons = [];
        if ($malformedCount > 0) {
            $reasons[] = 'malformed_claimable_count_positive';
        }
        if ($recoverableTotal > 0) {
            $reasons[] = 'recoverable_backlog_present';
        }
        if ($reasons !== [] && $hint === self::HINT_WAIT) {
            $hint = self::HINT_REPLENISH_SOON;
        }

        return [
            'hint' => $hint,
            'facts' => [
                'servable_now' => $servableNow,
                'active_leases' => $activeLeases,
                'recoverable_total' => $recoverableTotal,
                'malformed_claimable_count' => $malformedCount,
                'reasons' => $reasons,
            ],
        ];
    }
}
