<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use Throwable;

/**
 * Bounded runner for ONE scheduled project-lane daemon tick.
 *
 * Composes a lane runtime instance with {@see AtlasSelfConstructionRuntimeDaemonCycle}. Defaults to
 * dry-run (no callbacks invoked). In apply mode only INJECTED callbacks scoped to the lane's
 * `lane_id`, `queue_namespace` and `allowed_roots` may fire. Callback failures are isolated.
 *
 * Refuses any action that:
 *   - touches another lane's root,
 *   - lacks a namespace,
 *   - requires operator/human/external-provider/claude/codex/cursor,
 *   - uses git, network or unrestricted shell.
 */
final class AtlasProjectLaneRuntimeInstanceCycleRunner
{
    public const SCHEMA = 'atlas.project_lane.runtime_instance_cycle_runner.v1';

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

    /**
     * @param  array<string,mixed>  $instance lane runtime instance descriptor
     * @param  array<string,mixed>  $facts    {daemon_state, heartbeat_event, planned_actions, unattended_verdict?, native_pool_receipt?}
     * @param  array<string,mixed>  $options  {apply?:bool, action_callbacks?:array<string,callable>}
     * @return array<string,mixed>
     */
    public function run(array $instance, array $facts = [], array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $callbacks = is_array($options['action_callbacks'] ?? null) ? $options['action_callbacks'] : [];

        $laneId = (string) ($instance['lane_id'] ?? '');
        $projectId = (string) ($instance['project_id'] ?? '');
        $namespace = (string) ($instance['queue_namespace'] ?? '');
        $allowedRoots = array_values(array_map('strval', (array) ($instance['allowed_roots'] ?? [])));

        $plannedActions = array_values((array) ($facts['planned_actions'] ?? []));

        $applied = [];
        $blocked = [];
        $withheld = [];
        $laneReceipts = [];

        foreach ($plannedActions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $kind = (string) ($action['kind'] ?? '');
            $actionLane = (string) ($action['lane_id'] ?? $laneId);
            $actionNamespace = (string) ($action['queue_namespace'] ?? $namespace);
            $actionRoots = array_values((array) ($action['write_roots'] ?? []));

            if (in_array($kind, self::REFUSED_ACTION_KINDS, true)) {
                $withheld[] = ['kind' => $kind, 'reason' => 'refused_action_kind:'.$kind];

                continue;
            }
            if ($actionLane !== $laneId) {
                $withheld[] = ['kind' => $kind, 'reason' => 'cross_lane_action:'.$actionLane];

                continue;
            }
            if ($actionNamespace === '' || $actionNamespace !== $namespace) {
                $withheld[] = ['kind' => $kind, 'reason' => 'namespace_mismatch:'.$actionNamespace];

                continue;
            }
            foreach ($actionRoots as $root) {
                if (! $this->rootAllowed((string) $root, $allowedRoots)) {
                    $withheld[] = ['kind' => $kind, 'reason' => 'write_root_outside_lane:'.$root];

                    continue 2;
                }
            }
            if (! $apply) {
                $withheld[] = ['kind' => $kind, 'reason' => 'dry_run'];

                continue;
            }
            $cb = $callbacks[$kind] ?? null;
            if (! is_callable($cb)) {
                $withheld[] = ['kind' => $kind, 'reason' => 'no_callback_supplied'];

                continue;
            }
            try {
                $result = $cb($action, $instance);
                $applied[] = ['kind' => $kind, 'result' => is_array($result) ? $result : ['ok' => true]];
                $laneReceipts[] = [
                    'kind' => $kind,
                    'lane_id' => $laneId,
                    'project_id' => $projectId,
                    'receipt_hash' => hash('sha256', (string) json_encode(['action' => $action, 'result' => $result], JSON_UNESCAPED_SLASHES)),
                ];
            } catch (Throwable $e) {
                $blocked[] = ['kind' => $kind, 'error' => $e->getMessage()];
            }
        }

        $daemonCycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $daemonVerdict = $daemonCycle->tick($facts, ['apply' => false]); // facts-only projection, never apply through here.

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'lane_id' => $laneId,
            'project_id' => $projectId,
            'dry_run' => ! $apply,
            'planned_actions' => $plannedActions,
            'applied_actions' => $applied,
            'blocked_actions' => $blocked,
            'withheld_actions' => $withheld,
            'lane_receipts' => $laneReceipts,
            'daemon_status' => (string) ($daemonVerdict['daemon_status'] ?? 'unknown'),
        ];
        $payload['runner_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $allowedRoots
     */
    private function rootAllowed(string $root, array $allowedRoots): bool
    {
        $root = trim($root, '/');
        foreach ($allowedRoots as $allowed) {
            $a = trim((string) $allowed, '/');
            if ($a === '') {
                continue;
            }
            if ($root === $a || str_starts_with($root, $a.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['runner_hash']);
        ksort($copy);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
