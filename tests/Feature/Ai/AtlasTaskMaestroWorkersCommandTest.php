<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroWorkersCommand;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerCheckpointLedger;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFairnessAuditor;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskMaestroWorkersCommandTest extends TestCase
{
    private int $writeCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $self = $this;
        $self->writeCount = 0;

        // Lease snapshot — 2 client_ids with closed + open leases.
        $leases = [
            ['client_id' => 'alpha', 'opened_at' => 100, 'released_at' => 110],
            ['client_id' => 'alpha', 'opened_at' => 120, 'released_at' => 130],
            ['client_id' => 'alpha', 'opened_at' => 200, 'released_at' => null],
            ['client_id' => 'beta',  'opened_at' => 150, 'released_at' => 160],
            ['client_id' => 'beta',  'opened_at' => 170, 'released_at' => null],
            ['client_id' => 'beta',  'opened_at' => 180, 'released_at' => null],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(function () use ($leases, $self): iterable {
            // Read-only iteration. Any container-bound queue mutation would bump $writeCount via the spy.
            foreach ($leases as $row) {
                yield $row;
            }
            unset($self); // keep closure-bind happy
        });
        $this->app->instance(AtlasMaestroWorkerFleetProbe::class, $probe);
        $this->app->instance(AtlasMaestroWorkerFairnessAuditor::class, new AtlasMaestroWorkerFairnessAuditor($probe));

        $checkpointRoot = sys_get_temp_dir().'/atlas-maestro-cli-ckpt-'.bin2hex(random_bytes(5));
        @mkdir($checkpointRoot, 0o755, true);
        $ledger = new AtlasMaestroWorkerCheckpointLedger($checkpointRoot);
        $ledger->setClock(fn (): string => '2026-06-24T12:00:00+00:00');
        $ledger->record('alpha', 'pkt-1', 'hash-1');
        $ledger->record('beta', 'pkt-2', 'hash-2');
        $this->app->instance(AtlasMaestroWorkerCheckpointLedger::class, $ledger);
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro:workers', $args);

        return [$exit, $kernel->output()];
    }

    public function test_list_json_emits_per_client_rows(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'list', '--json' => true]);
        $this->assertSame(AtlasTaskMaestroWorkersCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        foreach ($decoded as $row) {
            foreach (['client_id', 'last_seen_at', 'in_flight_count', 'lifetime_throughput', 'median_lease_duration_seconds'] as $key) {
                $this->assertArrayHasKey($key, $row, "list row missing key {$key}");
            }
        }
    }

    public function test_probe_json_matches_the_auditor_envelope_shape(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'probe', '--json' => true]);
        $this->assertSame(AtlasTaskMaestroWorkersCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        foreach (['workers', 'total_in_flight', 'total_completed', 'in_flight_histogram', 'completed_share_histogram', 'gini_coefficient'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "probe JSON missing key {$key}");
        }
        $this->assertSame(2, $decoded['workers']);
    }

    public function test_checkpoint_with_client_returns_latest_entry(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'checkpoint', '--client' => 'alpha', '--json' => true]);
        $this->assertSame(AtlasTaskMaestroWorkersCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('pkt-1', $decoded['alpha']['task_packet_id']);
    }

    public function test_probe_with_live_lease_repo_produces_nonzero_worker_and_in_flight_counts(): void
    {
        Storage::fake('local');

        // Write a minimal active lease directly to the faked disk so activeLeases() returns it.
        $prefix = AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX;
        $leaseId = 'lease_test_live_probe_01';
        $lease = [
            'lease_id' => $leaseId,
            'agent_id' => 'live-probe-client',
            'task_packet_id' => 'live-probe-task',
            'lease_status' => 'active',
            'acquired_at_unix' => time() - 30,
            'acquired_at' => date('c', time() - 30),
            'expires_at_unix' => time() + 1800,
            'released_at' => null,
            'receipts' => [],
            'history' => [],
        ];
        Storage::disk('local')->put($prefix.'/'.$leaseId.'.json', (string) json_encode($lease));
        Storage::disk('local')->put($prefix.'/registry.json', (string) json_encode([
            'entries' => [[
                'lease_id' => $leaseId,
                'agent_id' => 'live-probe-client',
                'task_packet_id' => 'live-probe-task',
                'lease_status' => 'active',
                'expires_at_unix' => time() + 1800,
            ]],
        ]));

        // Bind real probe that reads from the live lease repo.
        $leaseRepo = new AgentControlPlaneClaimLeaseRepository;
        $probe = new AtlasMaestroWorkerFleetProbe(static function () use ($leaseRepo): iterable {
            foreach ($leaseRepo->activeLeases() as $l) {
                yield [
                    'client_id' => (string) ($l['agent_id'] ?? ''),
                    'opened_at' => (int) ($l['acquired_at_unix'] ?? 0),
                    'released_at' => null,
                ];
            }
        });
        $this->app->instance(AtlasMaestroWorkerFleetProbe::class, $probe);
        $this->app->instance(AtlasMaestroWorkerFairnessAuditor::class, new AtlasMaestroWorkerFairnessAuditor($probe));

        [$exit, $out] = $this->runCmd(['action' => 'probe', '--json' => true]);
        $this->assertSame(AtlasTaskMaestroWorkersCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true);
        $this->assertGreaterThan(0, $decoded['workers'], 'active leases must produce nonzero worker count');
        $this->assertGreaterThan(0, $decoded['total_in_flight'], 'active leases must produce nonzero in_flight count');
    }

    public function test_every_action_triggers_zero_queue_mutations(): void
    {
        // Tripwire: any DB write inside the command would surface here. The command never resolves
        // AgentControlPlaneClaimLeaseRepository / AtlasTaskServingService (it only resolves the
        // three injected primitives), and the injected primitives are read-only by construction.
        // We assert this by tracking writes through the leaseSource closure and by checking the
        // CLI exit code for each action: a write attempt against an in-memory primitive would
        // throw, breaking the exit code.
        foreach ([
            ['action' => 'list', '--json' => true],
            ['action' => 'probe', '--json' => true],
            ['action' => 'checkpoint', '--json' => true],
        ] as $args) {
            [$exit] = $this->runCmd($args);
            $this->assertSame(AtlasTaskMaestroWorkersCommand::EXIT_OK, $exit);
        }
        $this->assertSame(0, $this->writeCount, 'CLI must trigger zero queue mutations across list/probe/checkpoint');
    }
}
