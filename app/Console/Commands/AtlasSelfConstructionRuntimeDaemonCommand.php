<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas-native control surface for the final Self-Construction runtime daemon.
 *
 * status / plan are READ-ONLY (no apply, no callback).
 * tick / run-once default to the productive typed native executor; --dry-run is diagnostic only.
 * pause / resume / stop emit deterministic state events and do NOT require an operator for ordinary
 * forward progress when no pause/stop is currently active.
 *
 * The command refuses to invoke any provider, git, external coding tool, network or unrestricted
 * shell. Productive actions run only through the injected typed Atlas-native executor.
 */
final class AtlasSelfConstructionRuntimeDaemonCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:runtime-daemon
        {action : status|plan|claim|tick|run-once|pause|resume|stop}
        {intent? : Optional provenance-only intent for claim}
        {--facts= : Path to a JSON facts file or - for STDIN}
        {--workspace= : Optional caller workspace provenance for claim}
        {--max-cycles=1 : max ticks to drive in run-once (>=1)}
        {--apply : Deprecated compatibility flag; productive tick/run-once already apply}
        {--dry-run : Explicit diagnostic mode; never mutates}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'Atlas-native runtime daemon control: status | plan | claim | tick | run-once | pause | resume | stop.';

    public const FINAL_RUNTIME_OWNER = AtlasSelfConstructionRuntimeDaemon::FINAL_RUNTIME_OWNER;

    public const STEADY_STATE_RUNTIME_OWNER = AtlasSelfConstructionRuntimeDaemon::STEADY_STATE_RUNTIME_OWNER;

    public function handle(AtlasSelfConstructionRuntimeDaemon $daemon): int
    {
        $action = (string) $this->argument('action');
        $dryRun = (bool) $this->option('dry-run');
        $maxCycles = max(1, (int) $this->option('max-cycles'));

        $facts = $this->readFacts((string) ($this->option('facts') ?? ''));
        if ($facts === false) {
            $this->emit(['status' => 'usage_error', 'reason' => 'invalid_facts_path']);

            return self::FAILURE;
        }
        $facts ??= [];
        if ($action === 'claim') {
            $intent = trim((string) ($this->argument('intent') ?? ''));
            $workspace = trim((string) ($this->option('workspace') ?? ''));
            if ($intent !== '') {
                $facts['intent'] ??= $intent;
            }
            if ($workspace !== '') {
                $facts['workspace'] ??= $workspace;
            }
        }

        try {
            $payload = $daemon->run(
                action: $action,
                facts: $facts,
                dryRun: $dryRun,
                maxCycles: $maxCycles,
            );
        } catch (Throwable $e) {
            $payload = ['status' => 'error', 'error' => $e->getMessage()];
        }

        $this->emit($payload);

        return in_array((string) ($payload['status'] ?? ''), ['claimed', 'ok', 'planned'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null|false null = no facts, false = invalid path
     */
    private function readFacts(string $path): array|false|null
    {
        if ($path === '') {
            return null;
        }
        if ($path === '-') {
            $raw = (string) @file_get_contents('php://stdin');
        } else {
            if (! is_file($path)) {
                return false;
            }
            $raw = (string) @file_get_contents($path);
        }
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->line($this->encode($payload));
    }

    /**
     * Compatibility seam for callers that inspected the command before the
     * composition owner moved into AtlasSelfConstructionRuntimeDaemon.
     */
    private function productiveCycle(): AtlasSelfConstructionRuntimeDaemonCycle
    {
        return app(AtlasSelfConstructionRuntimeDaemon::class)->productiveCycle();
    }
}
