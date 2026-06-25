<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonState;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas-native control surface for the final Self-Construction runtime daemon.
 *
 * status / plan are READ-ONLY (no apply, no callback).
 * tick / run-once default to dry_run unless --apply is passed.
 * pause / resume / stop emit deterministic state events and do NOT require an operator for ordinary
 * forward progress when no pause/stop is currently active.
 *
 * The command refuses to invoke any provider, git, external coding tool, network or unrestricted
 * shell — it merely projects the daemon's facts and (when --apply) runs INJECTED Atlas-native
 * callbacks bound through the container (`atlas.self_construction.runtime_daemon.action_callbacks`).
 */
final class AtlasSelfConstructionRuntimeDaemonCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:runtime-daemon
        {action : status|plan|tick|run-once|pause|resume|stop}
        {--facts= : Path to a JSON facts file or - for STDIN}
        {--max-cycles=1 : max ticks to drive in run-once (>=1)}
        {--apply : Run the real Atlas-native callback path; default is dry-run}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'Atlas-native runtime daemon control: status | plan | tick | run-once | pause | resume | stop.';

    public const FINAL_RUNTIME_OWNER = 'atlas_native';

    public const STEADY_STATE_RUNTIME_OWNER = 'atlas_server';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $apply = (bool) $this->option('apply');
        $maxCycles = max(1, (int) $this->option('max-cycles'));

        $facts = $this->readFacts((string) ($this->option('facts') ?? ''));
        if ($facts === false) {
            $this->emit(['status' => 'usage_error', 'reason' => 'invalid_facts_path']);

            return self::FAILURE;
        }
        $facts ??= [];

        $callbacks = $this->resolveCallbacks();

        try {
            $payload = match ($action) {
                'status' => $this->statusAction($facts),
                'plan' => $this->planAction($facts),
                'tick' => $this->tickAction($facts, $apply, $callbacks),
                'run-once' => $this->runOnceAction($facts, $apply, $callbacks, $maxCycles),
                'pause' => $this->controlAction($facts, ['type' => 'pause_requested']),
                'resume' => $this->controlAction($facts, ['type' => 'resume']),
                'stop' => $this->controlAction($facts, ['type' => 'stop_requested']),
                default => ['status' => 'unknown_action', 'action' => $action],
            };
        } catch (Throwable $e) {
            $payload = ['status' => 'error', 'error' => $e->getMessage()];
        }

        $this->emit($payload);

        return ($payload['status'] ?? 'ok') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function statusAction(array $facts): array
    {
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'status',
            'dry_run' => true,
            'daemon_state' => $state,
            'safety_stop' => (bool) ($state['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($state['next_tick_allowed'] ?? false),
            'planned_actions' => array_values((array) ($facts['planned_actions'] ?? [])),
            'applied_actions' => [],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function planAction(array $facts): array
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $verdict = $cycle->tick($facts);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'plan',
            'dry_run' => true,
            'daemon_status' => (string) $verdict['daemon_status'],
            'safety_stop' => (bool) ($verdict['next_state']['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($verdict['next_state']['next_tick_allowed'] ?? false),
            'planned_actions' => $verdict['planned_actions'],
            'applied_actions' => $verdict['applied_actions'],
            'withheld_actions' => $verdict['withheld_actions'],
            'cycle_blocked_reasons' => $verdict['cycle_blocked_reasons'],
            'daemon_cycle_hash' => $verdict['daemon_cycle_hash'],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,callable>  $callbacks
     * @return array<string,mixed>
     */
    private function tickAction(array $facts, bool $apply, array $callbacks): array
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $verdict = $cycle->tick($facts, ['apply' => $apply, 'action_callbacks' => $callbacks]);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'tick',
            'dry_run' => $verdict['dry_run'],
            'daemon_status' => $verdict['daemon_status'],
            'safety_stop' => (bool) ($verdict['next_state']['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($verdict['next_state']['next_tick_allowed'] ?? false),
            'planned_actions' => $verdict['planned_actions'],
            'applied_actions' => $verdict['applied_actions'],
            'withheld_actions' => $verdict['withheld_actions'],
            'blocked_actions' => $verdict['blocked_actions'],
            'cycle_blocked_reasons' => $verdict['cycle_blocked_reasons'],
            'daemon_cycle_hash' => $verdict['daemon_cycle_hash'],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,callable>  $callbacks
     * @return array<string,mixed>
     */
    private function runOnceAction(array $facts, bool $apply, array $callbacks, int $maxCycles): array
    {
        $ticks = [];
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;

        for ($i = 0; $i < $maxCycles; $i++) {
            $tickFacts = array_replace($facts, ['daemon_state' => $state]);
            $verdict = $cycle->tick($tickFacts, ['apply' => $apply, 'action_callbacks' => $callbacks]);
            $ticks[] = $verdict;
            $state = $verdict['next_state'];
            if (! (bool) ($state['next_tick_allowed'] ?? false)) {
                break;
            }
        }

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'run-once',
            'dry_run' => ! $apply,
            'max_cycles' => $maxCycles,
            'cycle_count' => count($ticks),
            'ticks' => $ticks,
            'final_state' => $state,
            'daemon_status' => (string) ($state['status'] ?? 'unknown'),
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function controlAction(array $facts, array $event): array
    {
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $next = $reducer->reduce($state, $event);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => (string) $event['type'],
            'dry_run' => true,
            'daemon_status' => (string) $next['status'],
            'safety_stop' => (bool) ($next['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($next['next_tick_allowed'] ?? false),
            'next_state' => $next,
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * @return array<string,callable>
     */
    private function resolveCallbacks(): array
    {
        if (app()->bound('atlas.self_construction.runtime_daemon.action_callbacks')) {
            $bound = app('atlas.self_construction.runtime_daemon.action_callbacks');
            if (is_array($bound)) {
                return array_filter($bound, 'is_callable');
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function evidenceObligations(): array
    {
        return [
            'daemon_cycle_hash',
            'cycle_receipt_hash',
            'state_hash',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withOwnership(array $payload): array
    {
        return array_replace([
            'final_runtime_owner' => self::FINAL_RUNTIME_OWNER,
            'steady_state_runtime_owner' => self::STEADY_STATE_RUNTIME_OWNER,
        ], $payload);
    }

    /**
     * @return array<string,mixed>|null|false  null = no facts, false = invalid path
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
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
