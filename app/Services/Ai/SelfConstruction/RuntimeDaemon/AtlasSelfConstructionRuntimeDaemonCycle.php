<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use Throwable;

/**
 * Bounded server-side tick for the final 24/7 Atlas Self-Construction daemon.
 *
 * Composes (always, no I/O):
 *   - {@see AtlasSelfConstructionRuntimeDaemonState}::reduce — projects the next daemon state.
 *   - Caller-supplied unattended supervisor verdict — controls "critical blocker" stop.
 *   - Caller-supplied native worker pool receipt — surfaces in the receipt-hash for downstream gates.
 *
 * Refuses any action whose `kind` is in {@see REFUSED_ACTION_KINDS}: operator, human, external provider,
 * Claude / Codex / Cursor, git, network, unrestricted shell. These NEVER fire, even with a callback.
 *
 * Apply mode is opt-in (`options.apply === true`). Even then, ONLY injected Atlas-native callbacks
 * (`options.action_callbacks[kind]`) may run, and only for ready ticks (no safety_stop, no pause/stop
 * request, no stale heartbeat, no critical blocker from the unattended supervisor).
 */
final class AtlasSelfConstructionRuntimeDaemonCycle
{
    public const SCHEMA = 'atlas.self_construction.runtime_daemon_cycle.v1';

    public const REFUSED_ACTION_KINDS = [
        'operator_action',
        'human_action',
        'external_provider_call',
        'claude_code',
        'codex',
        'cursor',
        'git',
        'network',
        'unrestricted_shell',
    ];

    public function __construct(private readonly ?AtlasSelfConstructionRuntimeDaemonState $stateReducer = null)
    {
    }

    /**
     * @param  array<string,mixed>  $facts {daemon_state?, heartbeat_event, planned_actions?, unattended_verdict?, native_pool_receipt?}
     * @param  array<string,mixed>  $options {apply?:bool, action_callbacks?:array<string,callable>}
     * @return array<string,mixed>
     */
    public function tick(array $facts, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $callbacks = is_array($options['action_callbacks'] ?? null) ? $options['action_callbacks'] : [];

        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $heartbeatEvent = is_array($facts['heartbeat_event'] ?? null) ? $facts['heartbeat_event'] : ['type' => 'heartbeat'];
        $plannedActions = array_values((array) ($facts['planned_actions'] ?? []));
        $unattended = is_array($facts['unattended_verdict'] ?? null) ? $facts['unattended_verdict'] : [];
        $poolReceipt = is_array($facts['native_pool_receipt'] ?? null) ? $facts['native_pool_receipt'] : null;

        $reducer = $this->stateReducer ?? new AtlasSelfConstructionRuntimeDaemonState;
        $nextState = $reducer->reduce($state, $heartbeatEvent);

        $unattendedCriticalBlocker = (bool) ($unattended['critical_blocker'] ?? false);
        $nextTickAllowed = (bool) ($nextState['next_tick_allowed'] ?? false);
        $cycleBlockedReasons = [];
        if (! $nextTickAllowed) {
            $cycleBlockedReasons[] = 'daemon_state_blocks_tick:'.(string) ($nextState['status'] ?? 'unknown');
        }
        if ($unattendedCriticalBlocker) {
            $cycleBlockedReasons[] = 'unattended_supervisor_critical_blocker';
        }

        $appliedActions = [];
        $blockedActions = [];
        $withheldActions = [];

        foreach ($plannedActions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $kind = (string) ($action['kind'] ?? '');

            if (in_array($kind, self::REFUSED_ACTION_KINDS, true)) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'refused_action_kind:'.$kind,
                ];

                continue;
            }
            if (! $apply) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'dry_run',
                ];

                continue;
            }
            if ($cycleBlockedReasons !== []) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'cycle_blocked:'.$cycleBlockedReasons[0],
                ];

                continue;
            }
            $callback = $callbacks[$kind] ?? null;
            if (! is_callable($callback)) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'no_callback_supplied',
                ];

                continue;
            }
            try {
                $result = $callback($action, $nextState);
                $appliedActions[] = [
                    'kind' => $kind,
                    'result' => is_array($result) ? $result : ['ok' => true],
                ];
            } catch (Throwable $e) {
                $blockedActions[] = [
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $cycleReceiptHash = $this->cycleReceiptHash($nextState, $appliedActions, $blockedActions, $poolReceipt);

        $payload = [
            'schema_version' => self::SCHEMA,
            'daemon_status' => (string) ($nextState['status'] ?? 'unknown'),
            'dry_run' => ! $apply,
            'planned_actions' => $plannedActions,
            'applied_actions' => $appliedActions,
            'withheld_actions' => $withheldActions,
            'blocked_actions' => $blockedActions,
            'heartbeat_event' => $heartbeatEvent,
            'cycle_receipt_hash' => $cycleReceiptHash,
            'cycle_blocked_reasons' => $cycleBlockedReasons,
            'next_state' => $nextState,
        ];
        $payload['daemon_cycle_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $nextState
     * @param  list<array<string,mixed>>  $applied
     * @param  list<array<string,mixed>>  $blocked
     * @param  array<string,mixed>|null  $poolReceipt
     */
    private function cycleReceiptHash(array $nextState, array $applied, array $blocked, ?array $poolReceipt): string
    {
        $payload = [
            'daemon_status' => (string) ($nextState['status'] ?? ''),
            'state_hash' => (string) ($nextState['state_hash'] ?? ''),
            'applied_kinds' => array_values(array_map(static fn (array $a): string => (string) ($a['kind'] ?? ''), $applied)),
            'blocked_kinds' => array_values(array_map(static fn (array $a): string => (string) ($a['kind'] ?? ''), $blocked)),
            'pool_supervisor_hash' => (string) ($poolReceipt['supervisor_hash'] ?? ''),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['daemon_cycle_hash']);
        $copy = $this->ksortDeep($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
