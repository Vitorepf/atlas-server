<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleAuditTrail;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleOrchestrator;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCyclePhaseReceiptComposer;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleResumeManager;
use Illuminate\Console\Command;

/**
 * Operator-facing CLI for the LiveCycle integration primitives. Subcommands:
 *   start   — drive the 8-phase orchestrator, compose root_hash, persist a fact log.
 *   resume  — call the resume manager; refuse on tampered chain.
 *   status  — print last phase index, last receipt hash, master switch state.
 *   audit   — print operator-facing trail from the audit trail.
 *
 * Master switch fail-closed: when OFF, start/resume print "OFF" and exit 0 (byte-identical no-op).
 * status/audit are read-only and always work.
 */
final class AtlasLoopLiveCycleCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cycle:run
        {action : start|resume|status|audit}
        {--cycle-id=AUTO}
        {--state-dir=}
        {--facts-file=}
        {--last=10}
        {--json}';

    /** @var string */
    protected $description = 'LiveCycle CLI: start | resume | status | audit (operator-facing).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $masterOn = AtlasLoopMasterSwitch::enabled();

        return match ($action) {
            'start' => $this->doStart($masterOn),
            'resume' => $this->doResume($masterOn),
            'status' => $this->doStatus($masterOn),
            'audit' => $this->doAudit(),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function doStart(bool $masterOn): int
    {
        if (! $masterOn) {
            $this->line('OFF');

            return self::SUCCESS;
        }
        $cycleId = $this->cycleId();
        $stateDir = $this->stateDir();
        $factsFile = $this->factsFile($cycleId);

        $facts = [];
        $factSink = function (array $f) use (&$facts, $factsFile): void {
            $facts[] = $f;
            $this->appendFact($factsFile, $f);
        };
        $orch = new AtlasLoopLiveCycleOrchestrator();
        // Open the cycle.started fact.
        $factSink([
            'name' => 'cycle.started',
            'payload' => ['cycle_id' => $cycleId, 'started_at' => time()],
        ]);
        $result = $orch->run($cycleId, [], null, function (array $phaseFact) use ($factSink): void {
            $factSink([
                'name' => 'cycle.phase.completed',
                'payload' => $phaseFact + ['receipt_hash' => hash('sha256', (string) json_encode($phaseFact, JSON_UNESCAPED_SLASHES))],
            ]);
        });

        $phaseHashes = [];
        $resume = new AtlasLoopLiveCycleResumeManager($stateDir);
        foreach ($result['facts'] as $i => $f) {
            $h = hash('sha256', (string) json_encode($f, JSON_UNESCAPED_SLASHES));
            $phaseHashes[] = $h;
            $resume->checkpoint($cycleId, $i + 1, hash('sha256', $cycleId.':'.($i + 1)));
        }
        $composer = new AtlasLoopLiveCyclePhaseReceiptComposer();
        $composed = $composer->compose($cycleId, $phaseHashes);
        $factSink([
            'name' => 'cycle.receipt.composed',
            'payload' => ['cycle_id' => $cycleId, 'root_hash' => $composed['root_hash'], 'phase_receipt_hashes' => $phaseHashes, 'sub_ledger_links' => []],
        ]);
        $factSink([
            'name' => 'cycle.completed',
            'payload' => ['cycle_id' => $cycleId, 'completed_at' => time()],
        ]);

        $this->line((string) json_encode(['cycle_id' => $cycleId, 'root_hash' => $composed['root_hash']], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function doResume(bool $masterOn): int
    {
        if (! $masterOn) {
            $this->line('OFF');

            return self::SUCCESS;
        }
        $cycleId = (string) $this->option('cycle-id');
        if ($cycleId === '' || $cycleId === 'AUTO') {
            $this->line((string) json_encode(['error' => 'cycle_id required for resume'], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
        $resume = new AtlasLoopLiveCycleResumeManager($this->stateDir());
        $verdict = $resume->resume($cycleId);
        $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES));
        if (($verdict['refused'] ?? false) === true) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function doStatus(bool $masterOn): int
    {
        $cycleId = (string) $this->option('cycle-id');
        $stateDir = $this->stateDir();
        $resume = new AtlasLoopLiveCycleResumeManager($stateDir);
        $path = $resume->statePath($cycleId);
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $payload = [
            'cycle_id' => $cycleId,
            'master_switch' => $masterOn ? 'on' : 'off',
            'last_phase_index' => is_array($state) ? (int) ($state['last_good_phase_index'] ?? 0) : 0,
            'last_receipt_hash' => is_array($state) ? (string) ($state['last_receipt_hash'] ?? '') : '',
        ];
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function doAudit(): int
    {
        $factsFile = (string) ($this->option('facts-file') ?: ($this->stateDir().'/facts.jsonl'));
        $facts = $this->readFacts($factsFile);
        $store = new class($facts)
        {
            public function __construct(private array $facts) {}

            public function facts(string $pattern): array
            {
                return $this->facts;
            }
        };
        $trail = new AtlasLoopLiveCycleAuditTrail($store);
        $cycleId = (string) $this->option('cycle-id');
        if ($cycleId !== '' && $cycleId !== 'AUTO') {
            $this->line((string) json_encode($trail->describe($cycleId), JSON_UNESCAPED_SLASHES));
        } else {
            $rows = $trail->listCycles();
            $last = (int) $this->option('last');
            if ($last > 0 && count($rows) > $last) {
                $rows = array_slice($rows, -$last);
            }
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function failWith(string $reason): int
    {
        $this->line((string) json_encode(['error' => $reason], JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }

    private function cycleId(): string
    {
        $raw = (string) $this->option('cycle-id');
        if ($raw === '' || $raw === 'AUTO') {
            return 'cyc-'.bin2hex(random_bytes(6));
        }

        return $raw;
    }

    private function stateDir(): string
    {
        $raw = (string) $this->option('state-dir');
        if ($raw !== '') {
            return rtrim($raw, '/');
        }

        return storage_path('atlas/loop/live_cycle');
    }

    private function factsFile(string $cycleId): string
    {
        $raw = (string) $this->option('facts-file');
        if ($raw !== '') {
            return $raw;
        }

        return $this->stateDir().'/facts.jsonl';
    }

    private function appendFact(string $path, array $fact): void
    {
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($path, json_encode($fact, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readFacts(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        fclose($fh);

        return $out;
    }
}
