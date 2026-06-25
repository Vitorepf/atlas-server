<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionDeniedException;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelEnforcer;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelRegistry;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionReceipt;
use Illuminate\Console\Command;

/**
 * Operator observability surface for the Loop permission gradient pipeline
 *   Registry (P1) → Enforcer (P2) → Ledger (P3) → CLI (P4).
 *
 *   atlas:loop:permission inspect [--json]
 *   atlas:loop:permission enforce --phase=<p> --level=<l> [--json]
 *   atlas:loop:permission history [--phase=<p>] [--decision=allow|deny] [--limit=N] [--json]
 *
 * Provider-free, read-only by default; enforce records a receipt tagged dry_run=true.
 */
final class AtlasLoopPermissionCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_DENY = 2;

    public const EXIT_USAGE = 3;

    protected $signature = 'atlas:loop:permission {action : inspect|enforce|history}
        {--phase= : phase id (enforce|history filter)}
        {--level= : attempted level (enforce)}
        {--decision= : allow|deny (history filter)}
        {--limit=20 : history tail size}
        {--json}';

    protected $description = 'Loop permission-gradient operator CLI (inspect | enforce | history).';

    public function handle(
        AtlasLoopPermissionLevelRegistry $registry,
        AtlasLoopPermissionLevelEnforcer $enforcer,
        AtlasLoopPermissionLevelReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($registry),
            'enforce' => $this->enforce($enforcer, $ledger),
            'history' => $this->history($ledger),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function inspect(AtlasLoopPermissionLevelRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->phases() as $phase) {
            $rows[] = ['phase' => $phase, 'required_level' => $registry->requiredLevelFor($phase)->label()];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['phase'], $b['phase']));
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function enforce(AtlasLoopPermissionLevelEnforcer $enforcer, AtlasLoopPermissionLevelReceiptLedger $ledger): int
    {
        $phase = (string) ($this->option('phase') ?? '');
        $level = strtoupper((string) ($this->option('level') ?? ''));
        if ($phase === '' || $level === '') {
            return $this->refuse('enforce_requires_phase_and_level');
        }

        $masterOn = (bool) (function_exists('config') ? config('atlas.loop.master_enabled', false) : false);
        $allowed = true;
        $reason = 'allowed';
        try {
            $enforcer->assert($phase, $level);
        } catch (AtlasLoopPermissionDeniedException $e) {
            $allowed = false;
            $reason = $e->reason;
        }

        $receipt = new AtlasLoopPermissionReceipt(
            timestamp: gmdate('Y-m-d\TH:i:s\Z'),
            phase: $phase,
            attemptedLevel: $level,
            requiredLevel: $this->safeRequiredLevel($phase),
            decision: $allowed ? AtlasLoopPermissionReceipt::DECISION_ALLOW : AtlasLoopPermissionReceipt::DECISION_DENY,
            reason: $reason,
            masterSwitchState: $masterOn,
            callerChokepoint: 'atlas:loop:permission:enforce:dry_run',
        );
        $payload = $receipt->toArray();
        $payload['dry_run'] = true;
        // Best-effort record — ledger is master-gated, so OFF is a no-op.
        try {
            $ledger->record($receipt);
        } catch (\Throwable) {
            // never surface ledger failures from the dry-run CLI
        }

        $this->emit($payload);

        return $allowed ? self::EXIT_OK : self::EXIT_DENY;
    }

    private function history(AtlasLoopPermissionLevelReceiptLedger $ledger): int
    {
        $limit = (int) ($this->option('limit') ?? 20);
        if ($limit === 0) {
            $this->emit([]);

            return self::EXIT_OK;
        }
        $phaseFilter = (string) ($this->option('phase') ?? '');
        $decisionFilter = (string) ($this->option('decision') ?? '');
        $path = $ledger->path();
        if (! is_file($path)) {
            $this->emit([]);

            return self::EXIT_OK;
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($phaseFilter !== '' && (string) ($decoded['phase'] ?? '') !== $phaseFilter) {
                continue;
            }
            if ($decisionFilter !== '' && (string) ($decoded['decision'] ?? '') !== $decisionFilter) {
                continue;
            }
            $rows[] = $decoded;
        }
        if ($limit > 0) {
            $rows = array_values(array_slice($rows, -$limit));
        }
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function safeRequiredLevel(string $phase): string
    {
        try {
            return app(AtlasLoopPermissionLevelRegistry::class)->requiredLevelFor($phase)->label();
        } catch (\Throwable) {
            return 'UNKNOWN';
        }
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        if ($payload === []) {
            return;
        }
        if (array_is_list($payload)) {
            foreach ($payload as $row) {
                if (is_array($row)) {
                    $cells = [];
                    foreach ($row as $k => $v) {
                        $cells[] = $k.'='.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES));
                    }
                    $this->getOutput()->writeln(implode(' ', $cells));
                } else {
                    $this->getOutput()->writeln((string) $row);
                }
            }

            return;
        }
        foreach ($payload as $k => $v) {
            $this->getOutput()->writeln($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
