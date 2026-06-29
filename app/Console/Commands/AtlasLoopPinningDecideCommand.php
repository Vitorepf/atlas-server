<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasMaestroTaskPinningPolicy::decide()} at the operator surface: previews the
 * task-pinning decision for a (packet, worker) pair — allow (no_pin / pin_match) or refuse (pinned_to_other_
 * worker / invalid_worker_id) — from the pinning registry snapshot.
 *
 * Pure + read-only: it DECIDES only; it writes no pin and mutates nothing.
 */
final class AtlasLoopPinningDecideCommand extends Command
{
    protected $signature = 'atlas:loop:pinning-decide {--packet-id=} {--worker-id=} {--json}';

    protected $description = 'Read-only task-pinning decision for a packet/worker pair (no pin written).';

    public function handle(): int
    {
        $packetId = trim((string) $this->option('packet-id'));
        $workerId = trim((string) $this->option('worker-id'));
        if ($packetId === '' || $workerId === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'pinning-decide requires --packet-id=<id> and --worker-id=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decision = $this->policy()->decide($packetId, $workerId);

        $facts = ['schema' => 'atlas.loop.pinning_decide.v1'] + $decision;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('decision: '.$facts['decision'].'  reason: '.$facts['reason'].'  pinned_worker_id: '.($facts['pinned_worker_id'] ?? '-'));
        }

        return self::SUCCESS;
    }

    private function policy(): AtlasMaestroTaskPinningPolicy
    {
        $app = $this->getLaravel();
        if ($app->bound(AtlasMaestroTaskPinningPolicy::class)) {
            return $app->make(AtlasMaestroTaskPinningPolicy::class);
        }

        // The registry needs a concrete snapshot path (not autowireable): config with a storage fallback.
        $path = (string) config('atlas.maestro.task_pinning_snapshot_path', storage_path('atlas/maestro/task-pins.json'));

        return new AtlasMaestroTaskPinningPolicy(new AtlasMaestroTaskPinningRegistry($path));
    }
}
