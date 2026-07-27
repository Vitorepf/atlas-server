<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\AaeosDeferredPhaseDispatcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AAEOS Deferred Phase async worker (AP-696..AP-699 wiring).
 *
 * Drains the deferred phase queue produced by the HTTP path facade when
 * `atlas.aaeos.http_path_phase >= 3`. Each claimed envelope carries the
 * canonical `phase` field (topology/routing/spec/tasks/receipt) plus the
 * original envelope. The worker logs every claimed record to the canonical
 * application log so the operator can audit which deferred phases were
 * picked up and when.
 *
 * Modes:
 *   --once : drain a single batch and exit (cron-friendly).
 *   --max=<n> : maximum records to claim per batch (default 16).
 *   --loop  : run a continuous loop with sleep between batches.
 *   --interval=<seconds> : sleep between iterations when --loop (default 5).
 *
 * Examples:
 *   php artisan atlas:aeos:deferred-worker --once --max=32 --json
 *   php artisan atlas:aeos:deferred-worker --loop --interval=2
 *
 * Future: a follow-up AP can replace this logger-only worker with one that
 * invokes Spec OS / Work Splitter / Decision Receipt v2 runtime synchronously
 * per claimed record. The contract (claim -> execute -> ack) is set here.
 */
final class AtlasAaeosDeferredWorkerCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aeos:deferred-worker
        {--once : drain a single batch and exit}
        {--loop : run continuously with --interval sleep between batches}
        {--max=16 : maximum records claimed per batch}
        {--interval=5 : seconds between iterations when --loop}
        {--json : machine-readable JSON output}';

    protected $description = 'Drain AAEOS deferred phase queue produced by the HTTP facade (AP-696..AP-699). [was atlas:aaeos:*; TRI-HYGIENE rename]';

    /**
     * Deprecated name kept working natively. It used to need a whole forwarding
     * command class; Laravel applies this in Command::__construct.
     */
    protected $aliases = ['atlas:aaeos:deferred-worker'];

    public function handle(AaeosDeferredPhaseDispatcherService $dispatcher): int
    {
        $max = max(1, (int) $this->option('max'));
        $loop = (bool) $this->option('loop');
        $once = (bool) $this->option('once') || ! $loop;
        $interval = max(1, (int) $this->option('interval'));
        $json = (bool) $this->option('json');

        $totalClaimed = 0;

        do {
            $claimed = $dispatcher->claim(max: $max);
            $totalClaimed += count($claimed);

            foreach ($claimed as $record) {
                $phase = (string) ($record['phase'] ?? 'unknown');
                $intentId = (string) ($record['intent_id'] ?? '');
                $dispatchId = (string) ($record['dispatch_id'] ?? '');
                Log::channel(config('logging.default'))->info('atlas.aaeos.deferred_worker.claimed', [
                    'phase' => $phase,
                    'intent_id' => $intentId,
                    'dispatch_id' => $dispatchId,
                    'enqueued_at' => $record['enqueued_at'] ?? null,
                    'envelope_phase_out' => $record['envelope']['phase_out'] ?? null,
                ]);

                if (! $json) {
                    $this->line(sprintf('  claimed %s · phase=%s intent=%s', $dispatchId, $phase, $intentId));
                }
            }

            if ($once) {
                break;
            }
            if ($claimed === []) {
                sleep($interval);
            }
        } while (! $once);

        $summary = [
            'schema' => 'atlas.aaeos.deferred_worker.summary.v1',
            'mode' => $once ? 'once' : 'loop',
            'total_claimed' => $totalClaimed,
            'pending_at_exit' => $dispatcher->pendingCount(),
        ];

        if ($json) {
            $this->line($this->encode($summary));
        } else {
            $this->info(sprintf('Drained %d records · pending %d', $totalClaimed, $summary['pending_at_exit']));
        }

        return self::SUCCESS;
    }
}
