<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseEnforcer;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseRegistry;
use Illuminate\Console\Command;

/**
 * Operator-driveable surface for the per-phase anchor gate.
 *
 *   atlas:loop:anchor:per-phase inspect --phase=<id>
 *   atlas:loop:anchor:per-phase enforce  --phase=<id> --payload=<path> [--cycle=<id>]
 *   atlas:loop:anchor:per-phase history  [--phase=<id>] [--cycle=<id>] [--limit=N]
 */
final class AtlasLoopAnchorGatePerPhaseCli extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_REFUSE = 1;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:anchor:per-phase {action : inspect|enforce|history}
        {--phase= : Loop phase id}
        {--cycle= : Loop cycle id (enforce/history)}
        {--payload= : payload JSON path (enforce)}
        {--limit=20 : history cap}
        {--json : machine output}';

    protected $description = 'Per-phase anchor-gate CLI: inspect | enforce | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect(),
            'enforce' => $this->enforce(),
            'history' => $this->history(),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function inspect(): int
    {
        $phase = (string) ($this->option('phase') ?? '');
        if ($phase === '') {
            return $this->failWith('phase_option_missing');
        }
        $registry = app(AtlasLoopAnchorGatePerPhaseRegistry::class);
        try {
            $profile = $registry->profileFor($phase);
        } catch (\Throwable $e) {
            return $this->failWith('inspect_failed:'.$e->getMessage());
        }
        $this->emit($profile->toArray());

        return self::EXIT_OK;
    }

    private function enforce(): int
    {
        $phase = (string) ($this->option('phase') ?? '');
        $payloadPath = (string) ($this->option('payload') ?? '');
        $cycle = (string) ($this->option('cycle') ?? 'cli-cycle');
        if ($phase === '' || $payloadPath === '' || ! is_file($payloadPath)) {
            return $this->failWith('enforce_missing_required_options');
        }
        $raw = (string) file_get_contents($payloadPath);
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return $this->failWith('payload_not_valid_json');
        }

        $enforcer = app(AtlasLoopAnchorGatePerPhaseEnforcer::class);
        $ledger = app(AtlasLoopAnchorGatePerPhaseReceiptLedger::class);
        $verdict = $enforcer->enforce($phase, $payload);
        $ledger->recordVerdict($cycle, $phase, $verdict, $payload);

        $this->emit($verdict->toArray());

        return $verdict->allow ? self::EXIT_OK : self::EXIT_REFUSE;
    }

    private function history(): int
    {
        $ledger = app(AtlasLoopAnchorGatePerPhaseReceiptLedger::class);
        $phase = (string) ($this->option('phase') ?? '');
        $cycle = (string) ($this->option('cycle') ?? '');
        $limit = max(1, (int) ($this->option('limit') ?? 20));

        if ($phase !== '') {
            $rows = $ledger->recentForPhase($phase, $limit);
            if ($cycle !== '') {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['loop_cycle_id'] ?? '') === $cycle));
            }
        } elseif ($cycle !== '') {
            $rows = array_slice($ledger->recentForCycle($cycle), -$limit);
        } else {
            return $this->failWith('history_requires_phase_or_cycle');
        }

        $this->emit(['rows' => $rows]);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
