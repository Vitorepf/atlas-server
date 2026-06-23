<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskSwarmProofService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PART 2 · the SWARM PROOF harness (a Self-Construction tool the operator's loop explicitly asks for: "um
 * simulador de N-clientes concorrentes que tenta forçar colisão"). It proves conflict-free serving with REAL
 * OS-level concurrency — not the sequential two-call logic the A7 contract test settles for.
 *
 * GOLD STANDARD: it spawns N independent `php artisan` worker processes (each an opaque client calling the
 * SAME {@see AtlasTaskServingService::next} surface), pointed at ONE isolated storage root so they contend on
 * the SAME real flock + the SAME real queue files — exactly the production shape (N AIs pulling tasks at once).
 * A wall-clock release BARRIER makes them all fire `next` within sub-millisecond of each other, maximizing the
 * chance of forcing a double-claim if the locking is wrong. The observed envelopes are judged by the frozen
 * {@see AtlasTaskSwarmProofService} into a conflict-free X-RAY artifact (the raio-X the cycle demands).
 *
 * Isolation invariants (honest, non-destructive):
 *   - Runs against a throwaway temp root + its OWN temp env file with the master switch ON — it NEVER touches
 *     the operator's real `.env` (the switch stays pétreo-OFF for the real loop).
 *   - The seeded packets live only under the temp root; nothing leaks into the live serving queue.
 */
class AtlasTaskSwarmProofCommand extends Command
{
    protected $signature = 'atlas:task:swarm-proof
        {--clients=8 : number of concurrent client processes per round}
        {--rounds=2 : independent contention rounds (fresh root each round)}
        {--scenario=mixed : disjoint|mixed — mixed adds a write-set-colliding pair to stress conflict rejection}
        {--lead=1.5 : seconds the coordinator waits so all workers reach the barrier before it releases}
        {--artifact= : path to write the X-ray JSON (default: storage/app/atlas/swarm-proof/xray-<run>.json)}
        {--keep : keep the temp roots after the run (default: cleaned up)}
        {--json : print the X-ray JSON}

        {--worker : INTERNAL — run as a single concurrent client (spawned by the coordinator)}
        {--root= : INTERNAL — isolated storage root for this worker}
        {--client= : INTERNAL — opaque client id for this worker}
        {--master-env= : INTERNAL — temp .env path that flips the master switch ON for this run}
        {--barrier-at= : INTERNAL — microtime(true) wall-clock instant at which the worker fires `next`}
        {--out= : INTERNAL — file the worker writes its observation JSON to}';

    protected $description = 'Force REAL N-client concurrency on the task-serving contract and emit the conflict-free X-ray (the proof the operator asked for).';

    private const LEASE_DIR = 'atlas/self-construction/agent-control-plane/leases';

    private const QUEUE_DIR = 'atlas/self-construction/agent-control-plane/task-queue';

    public function handle(AtlasTaskSwarmProofService $analyzer): int
    {
        return $this->option('worker') ? $this->runWorker() : $this->runCoordinator($analyzer);
    }

    // --- WORKER: one opaque client, fires `next` at the shared barrier ------------------------------------

    private function runWorker(): int
    {
        $root = (string) $this->option('root');
        $client = (string) $this->option('client');
        $out = (string) $this->option('out');
        $barrierAt = (float) $this->option('barrier-at');
        $masterEnv = (string) $this->option('master-env');

        // Flip the master switch ON for THIS process only — via the documented test seam, never the real .env.
        if ($masterEnv !== '') {
            AtlasLoopMasterSwitch::$envPathOverride = $masterEnv;
        }

        $envelope = ['status' => 'error', 'reason' => 'worker_uninitialized'];
        $firedAt = 0.0;
        try {
            $serving = new AtlasTaskServingService($this->isolatedOrchestrator($root));
            // Spin to the release barrier so every client hits the lock at the same instant.
            while (microtime(true) < $barrierAt) {
                usleep(200);
            }
            $firedAt = microtime(true);
            $envelope = $serving->next($client);
        } catch (Throwable $e) {
            $envelope = ['status' => 'error', 'reason' => 'worker_exception', 'message' => $e->getMessage()];
        }

        $observation = ['client_id' => $client, 'fired_at' => $firedAt, 'barrier_at' => $barrierAt, 'envelope' => $envelope];
        if ($out !== '') {
            @file_put_contents($out, (string) json_encode($observation, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    // --- COORDINATOR: seed, spawn the swarm, collect, judge, emit ------------------------------------------

    private function runCoordinator(AtlasTaskSwarmProofService $analyzer): int
    {
        $clients = max(2, (int) $this->option('clients'));
        $rounds = max(1, (int) $this->option('rounds'));
        $scenario = (string) $this->option('scenario');
        $lead = max(0.3, (float) $this->option('lead'));

        $runId = 'swarm-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $swarmRoot = storage_path('app/atlas/swarm-proof/'.$runId);
        $masterEnv = $swarmRoot.'/master.env';
        @mkdir($swarmRoot, 0775, true);
        @file_put_contents($masterEnv, AtlasLoopMasterSwitch::KEY."=true\n");

        $roundResults = [];
        for ($r = 0; $r < $rounds; $r++) {
            $roundResults[] = $this->runRound($r, $clients, $scenario, $lead, $swarmRoot, $masterEnv);
        }

        $xray = $analyzer->analyze($roundResults);
        $xray['run_id'] = $runId;
        $xray['clients_per_round'] = $clients;
        $xray['scenario'] = $scenario;
        $xray['barrier_spread'] = $this->barrierSpread($roundResults);

        $artifact = (string) ($this->option('artifact') ?: storage_path('app/atlas/swarm-proof/xray-'.$runId.'.json'));
        @mkdir(\dirname($artifact), 0775, true);
        @file_put_contents($artifact, (string) json_encode($xray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $xray['artifact'] = $artifact;

        if (! $this->option('keep')) {
            $this->rmrf($swarmRoot);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($xray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSummary($xray);
        }

        return $xray['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One independent contention round: seed packets, release N workers at one barrier, collect observations.
     *
     * @return array<string, mixed>
     */
    private function runRound(int $round, int $clients, string $scenario, float $lead, string $swarmRoot, string $masterEnv): array
    {
        $root = $swarmRoot.'/round-'.$round;
        $this->ensureStorageDirs($root);

        $specs = $this->packetSpecs($scenario, $round);
        $this->seedPackets($root, $round, $specs);

        $barrierAt = microtime(true) + $lead;
        $outDir = $root.'/observations';
        @mkdir($outDir, 0775, true);

        /** @var array<int, array{process: Process, client: string, out: string}> $running */
        $running = [];
        for ($k = 0; $k < $clients; $k++) {
            $client = 'swarm-client-'.$round.'-'.$k;
            $outFile = $outDir.'/'.$client.'.json';
            $process = new Process([
                PHP_BINARY, base_path('artisan'), 'atlas:task:swarm-proof',
                '--worker',
                '--root='.$root,
                '--client='.$client,
                '--master-env='.$masterEnv,
                '--barrier-at='.sprintf('%.6f', $barrierAt),
                '--out='.$outFile,
            ], base_path());
            $process->setTimeout(60);
            $process->start();
            $running[] = ['process' => $process, 'client' => $client, 'out' => $outFile];
        }

        $observations = [];
        foreach ($running as $entry) {
            $entry['process']->wait();
            $exit = (int) $entry['process']->getExitCode();
            $decoded = is_file($entry['out']) ? json_decode((string) file_get_contents($entry['out']), true) : null;
            $observations[] = [
                'client_id' => $entry['client'],
                'exit_code' => $exit,
                'fired_at' => is_array($decoded) ? (float) ($decoded['fired_at'] ?? 0.0) : 0.0,
                'barrier_at' => $barrierAt,
                'envelope' => is_array($decoded) ? (array) ($decoded['envelope'] ?? []) : ['status' => 'missing'],
            ];
        }

        return ['round' => $round, 'enqueued' => $specs, 'observations' => $observations];
    }

    /**
     * Packet specs for a round. `disjoint`: every write-set is independent. `mixed`: adds a colliding pair
     * (a bare dir + a file beneath it) so the lease conflict-rejection is actually exercised under contention.
     *
     * @return list<array{task_packet_id:string, write_set:list<string>, read_set:list<string>}>
     */
    private function packetSpecs(string $scenario, int $round): array
    {
        $p = 'r'.$round.'-';
        $specs = [
            ['task_packet_id' => $p.'alpha', 'write_set' => ['app/Services/Ai/SelfConstruction/SwarmAlpha'.$round.'.php'], 'read_set' => []],
            ['task_packet_id' => $p.'beta', 'write_set' => ['app/Services/Ai/SelfConstruction/SwarmBeta'.$round.'.php'], 'read_set' => []],
            ['task_packet_id' => $p.'gamma', 'write_set' => ['app/Services/Ai/SelfConstruction/SwarmGamma'.$round.'.php'], 'read_set' => []],
        ];
        if ($scenario === 'mixed') {
            // A colliding pair: only ONE of these may ever be held concurrently (write-vs-write, file ⊂ dir).
            $specs[] = ['task_packet_id' => $p.'collide-a', 'write_set' => ['app/Services/Ai/SelfConstruction/SwarmCollide'.$round.'.php'], 'read_set' => []];
            $specs[] = ['task_packet_id' => $p.'collide-b', 'write_set' => ['app/Services/Ai/SelfConstruction/SwarmCollide'.$round.'.php'], 'read_set' => []];
        }

        return $specs;
    }

    /**
     * @param  list<array<string,mixed>>  $specs
     */
    private function seedPackets(string $root, int $round, array $specs): void
    {
        $orch = $this->isolatedOrchestrator($root);
        foreach ($specs as $spec) {
            $orch->prepareAndEnqueue(['task_packet' => [
                'task_packet_id' => (string) $spec['task_packet_id'],
                'objective' => 'swarm-proof contention packet '.$spec['task_packet_id'],
                'operator_id' => 'swarm-proof',
                'allowed_files' => (array) $spec['write_set'],
                'scope_in' => array_values(array_unique(array_merge((array) $spec['write_set'], (array) $spec['read_set']))),
                'acceptance_criteria' => ['conflict_free_contention'],
                'required_evidence' => ['task_packet_created'],
            ]]);
        }
    }

    private function isolatedOrchestrator(string $root): AgentControlPlaneTaskQueueOrchestrator
    {
        $disk = 'swarm_proof_'.substr(md5($root), 0, 10);
        Config::set('filesystems.disks.'.$disk, ['driver' => 'local', 'root' => $root, 'throw' => false]);

        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository($disk),
            new AgentControlPlaneClaimLeaseRepository($disk),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function ensureStorageDirs(string $root): void
    {
        @mkdir($root.'/'.self::LEASE_DIR, 0775, true);
        @mkdir($root.'/'.self::QUEUE_DIR, 0775, true);
    }

    /** @param list<array<string,mixed>> $roundResults */
    private function barrierSpread(array $roundResults): array
    {
        $spreads = [];
        foreach ($roundResults as $round) {
            $fired = array_values(array_filter(array_map(
                static fn (array $o): float => (float) ($o['fired_at'] ?? 0.0),
                (array) ($round['observations'] ?? []),
            ), static fn (float $f): bool => $f > 0.0));
            if (count($fired) >= 2) {
                $spreads[] = round((max($fired) - min($fired)) * 1000.0, 3); // ms between first and last fire
            }
        }

        return ['per_round_ms' => $spreads, 'max_ms' => $spreads === [] ? null : max($spreads)];
    }

    private function renderSummary(array $xray): void
    {
        $this->line('');
        $this->line('  <fg=cyan>SWARM PROOF — conflict-free X-ray</> ('.$xray['rounds'].' rounds × '.$xray['clients_per_round'].' clients, scenario='.$xray['scenario'].')');
        $this->line('  served='.$xray['totals']['served'].'  no_claimable='.$xray['totals']['no_claimable_task'].'  observations='.$xray['totals']['observations']);
        $this->line('  double_claims='.count($xray['double_claims']).'  held_overlaps='.count($xray['held_overlaps']).'  r2_breaches='.count($xray['r2_breaches']).'  phantom='.count($xray['phantom_serves']));
        $this->line('  barrier_spread_max='.($xray['barrier_spread']['max_ms'] ?? 'n/a').'ms  artifact='.($xray['artifact'] ?? ''));
        $this->line($xray['passed']
            ? '  <fg=black;bg=green> PASS </> conflict-free under real concurrency'
            : '  <fg=white;bg=red> FAIL </> a serving invariant broke under concurrency — see the X-ray');
        $this->line('');
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
