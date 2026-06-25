<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopFullCycleConductor;
use Illuminate\Console\Command;

/**
 * Read-end of the UnifiedReceiptChain. Defined locally because the chain interface (write-end) lives in the
 * LiveCycle namespace and is outside this packet's allowed_files — the CLI binds a reader via the container.
 */
interface UnifiedReceiptChainReader
{
    /**
     * Per-phase receipts for one cycle id, in canonical phase order.
     *
     * @return array<string, array<string,mixed>>  phase => receipt
     */
    public function forCycle(string $cycleId): array;

    /**
     * Most-recent cycle SUMMARY receipts, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $limit): array;
}

/**
 * Operator surface for the 8-phase live cycle.
 *
 *   run     [--scope=X] [--json]           Drive AtlasLoopFullCycleConductor::runCycle, print the cycle receipt.
 *                                          Refused when ATLAS_LOOP_MASTER_ENABLED=false (exit 1).
 *   inspect --cycle-id=ID [--json]         Read the receipt chain for a known cycle and print all 8 per-phase
 *                                          receipts in canonical phase order.
 *   history [--limit=20] [--json]          List the last N cycle summary receipts. Read-only stays available
 *                                          even when the master switch is OFF.
 *
 * Exit codes: 0 = ok, 1 = master_switch_off / refusal, 2 = usage error.
 */
final class AtlasLoopCycleCommand extends Command
{
    public const READER_KEY = 'atlas.loop.cycle.receipt_chain_reader';

    public const EXIT_OK = 0;

    public const EXIT_REFUSED = 1;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:cycle {action : run|inspect|history} {--scope=} {--cycle-id=} {--limit=20} {--json}';

    protected $description = 'Operator CLI for the canonical 8-phase live cycle: run | inspect | history.';

    public function handle(AtlasLoopFullCycleConductor $conductor): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'run' => $this->runCycle($conductor),
            'inspect' => $this->inspect(),
            'history' => $this->history(),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function runCycle(AtlasLoopFullCycleConductor $conductor): int
    {
        if (! $this->masterSwitchOn()) {
            $this->emit(['final_status' => 'refused', 'reason' => 'master_switch_off'], 'master_switch_off');

            return self::EXIT_REFUSED;
        }

        $scope = ['scope' => trim((string) $this->option('scope')), 'cycle_id' => trim((string) $this->option('cycle-id')) ?: ('cycle-'.bin2hex(random_bytes(4)))];
        $result = $conductor->runCycle($scope);
        $this->emit($result, 'final_status='.($result['final_status'] ?? 'unknown'));

        return self::EXIT_OK;
    }

    private function inspect(): int
    {
        $cycleId = trim((string) $this->option('cycle-id'));
        if ($cycleId === '') {
            return $this->usage('--cycle-id is required for inspect');
        }

        $reader = $this->reader();
        $perPhase = $reader !== null ? $reader->forCycle($cycleId) : [];

        // Canonical phase order, even if some are missing (rendered as null so consumers see the slot).
        $ordered = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $ordered[$phase] = $perPhase[$phase] ?? null;
        }
        $this->emit(['cycle_id' => $cycleId, 'phases' => $ordered], 'inspected '.$cycleId);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $reader = $this->reader();
        $rows = $reader !== null ? $reader->history($limit) : [];

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line(sprintf('cycle=%s status=%s', (string) ($row['cycle_id'] ?? ''), (string) ($row['final_status'] ?? '')));
            }
        }

        return self::EXIT_OK;
    }

    private function reader(): ?UnifiedReceiptChainReader
    {
        if (! app()->bound(self::READER_KEY)) {
            return null;
        }
        $reader = app(self::READER_KEY);

        return $reader instanceof UnifiedReceiptChainReader ? $reader : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line($humanLine);
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }

    private function masterSwitchOn(): bool
    {
        return AtlasLoopMasterSwitch::enabled();
    }
}
