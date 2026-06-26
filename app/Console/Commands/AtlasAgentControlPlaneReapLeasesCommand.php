<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;

/**
 * PART 2 · A3/MF-05 — the SCHEDULED reaper that closes the dead-agent strand.
 *
 * The recovery LOGIC already existed ({@see AgentControlPlaneTaskLeaseRecoveryService}) but NOTHING ran it:
 * `expireLeasesInternal` only flipped `lease_status`, leaving the queue record stuck in `claimed` forever, and
 * no scheduler invoked recovery — so a client that died after claiming stranded its task until the 14400s TTL,
 * and even then the queue stayed `claimed`. This command expires TTL-passed leases AND returns their tasks to
 * `claimable`, reclaims orphaned claims, AND re-admits transient give-backs (status `released`) that would
 * otherwise drain the queue forever. Read-only-safe: it only releases stranded work back to the queue, never
 * dispatches, spends, or merges. Scheduled every minute (gated by the loop MASTER SWITCH).
 */
class AtlasAgentControlPlaneReapLeasesCommand extends Command
{
    protected $signature = 'atlas:acp:reap-leases {--json : Print machine-readable JSON}';

    protected $description = 'Reap expired Agent Control Plane leases and return their stranded tasks to claimable (R2 dead-agent recovery).';

    public function handle(): int
    {
        // Recovery must run on the operator SERVING disk; container auto-resolution would
        // hand back a default-disk instance and the every-minute reaper would silently
        // never recover real stranded leases.
        $recovery = new AgentControlPlaneTaskLeaseRecoveryService(
            AtlasTaskServingStack::queueRepo(),
            AtlasTaskServingStack::leaseRepo(),
        );
        try {
            $expired = $recovery->recoverExpiredLeases(['actor' => 'scheduled_reaper']);
            $orphaned = $recovery->recoverOrphanedClaims(['actor' => 'scheduled_reaper']);
            // Give-backs (status `released`) drain the queue if never re-admitted — recover the transient ones
            // (the recovery itself skips released-with-blocker reasons that need operator investigation).
            $released = $recovery->recoverReleasedTasks(['actor' => 'scheduled_reaper']);
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
            $this->emit($payload);

            return self::FAILURE;
        }

        $payload = [
            'ok' => true,
            'expired_recovered' => (int) ($expired['recovered_count'] ?? 0),
            'expired_skipped' => (int) ($expired['skipped_count'] ?? 0),
            'orphaned_recovered' => (int) ($orphaned['recovered_count'] ?? 0),
            'orphaned_skipped' => (int) ($orphaned['skipped_count'] ?? 0),
            'released_recovered' => (int) ($released['recovered_count'] ?? 0),
            'released_skipped' => (int) ($released['skipped_count'] ?? 0),
        ];
        $this->emit($payload);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return;
        }
        if (($payload['ok'] ?? false) === true) {
            $this->info(sprintf(
                'reaped: %d expired + %d orphaned + %d released returned to claimable',
                $payload['expired_recovered'] ?? 0,
                $payload['orphaned_recovered'] ?? 0,
                $payload['released_recovered'] ?? 0,
            ));

            return;
        }
        $this->error((string) ($payload['error'] ?? 'reap failed'));
    }
}
