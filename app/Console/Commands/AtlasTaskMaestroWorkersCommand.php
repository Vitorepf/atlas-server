<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerCheckpointLedger;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFairnessAuditor;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use Illuminate\Console\Command;

/**
 * Read-only operator surface over the three Maestro worker primitives.
 *
 *   list        AtlasMaestroWorkerFleetProbe::probe()        — per-client_id rollup.
 *   probe       AtlasMaestroWorkerFairnessAuditor::audit()   — histogram + Gini + max-share.
 *   checkpoint  AtlasMaestroWorkerCheckpointLedger::latest(client)  — restart-resume mark.
 *
 * INVARIANT: this command MUST NOT enqueue, dequeue, release, or rebalance anything. The trio it wires is
 * itself read-only; this CLI never touches the queue repository or serving service directly.
 */
final class AtlasTaskMaestroWorkersCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:task:maestro:workers {action : list|probe|checkpoint} {--client= : restrict to a client_id} {--json}';

    protected $description = 'Read-only Maestro worker coordination CLI: list | probe | checkpoint.';

    public function handle(
        AtlasMaestroWorkerFleetProbe $probe,
        AtlasMaestroWorkerFairnessAuditor $auditor,
        AtlasMaestroWorkerCheckpointLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'list' => $this->list($probe),
            'probe' => $this->probeAction($auditor),
            'checkpoint' => $this->checkpoint($ledger, $probe),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function list(AtlasMaestroWorkerFleetProbe $probe): int
    {
        $rows = $probe->probe();
        $client = trim((string) $this->option('client'));
        if ($client !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['client_id'] ?? '') === $client));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::EXIT_OK;
        }
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  %s last_seen=%d in_flight=%d throughput=%d median=%.3f',
                $r['client_id'] ?? '',
                (int) ($r['last_seen_at'] ?? 0),
                (int) ($r['in_flight_count'] ?? 0),
                (int) ($r['lifetime_throughput'] ?? 0),
                (float) ($r['median_lease_duration_seconds'] ?? 0),
            ));
        }

        return self::EXIT_OK;
    }

    private function probeAction(AtlasMaestroWorkerFairnessAuditor $auditor): int
    {
        $report = $auditor->audit();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::EXIT_OK;
        }
        $this->line(sprintf(
            'workers=%d in_flight=%d completed=%d gini=%.4f max_share=%s@%.4f',
            (int) ($report['workers'] ?? 0),
            (int) ($report['total_in_flight'] ?? 0),
            (int) ($report['total_completed'] ?? 0),
            (float) ($report['gini_coefficient'] ?? 0),
            (string) ($report['max_share_client_id'] ?? '-'),
            (float) ($report['max_share_value'] ?? 0),
        ));
        foreach ((array) ($report['completed_share_histogram'] ?? []) as $cid => $share) {
            $this->line(sprintf('  %s share=%.4f', $cid, (float) $share));
        }

        return self::EXIT_OK;
    }

    private function checkpoint(AtlasMaestroWorkerCheckpointLedger $ledger, AtlasMaestroWorkerFleetProbe $probe): int
    {
        $client = trim((string) $this->option('client'));
        $payload = [];
        if ($client !== '') {
            $payload[$client] = $ledger->latest($client);
        } else {
            foreach ($probe->probe() as $row) {
                $cid = (string) ($row['client_id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $payload[$cid] = $ledger->latest($cid);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::EXIT_OK;
        }
        foreach ($payload as $cid => $entry) {
            if ($entry === null) {
                $this->line(sprintf('  %s (no checkpoint)', $cid));

                continue;
            }
            $this->line(sprintf(
                '  %s seq=%d task=%s payload_hash=%s',
                $cid,
                (int) ($entry['sequence'] ?? 0),
                (string) ($entry['task_packet_id'] ?? ''),
                (string) ($entry['payload_hash'] ?? ''),
            ));
        }

        return self::EXIT_OK;
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
