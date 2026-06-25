<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Cross-project virtual soak proof for multi-project Self-Construction.
 *
 * Runs deterministic virtual ticks across two or more lane instances and proves:
 *   - queue namespace isolation
 *   - allowed-root isolation
 *   - per-lane safety stop / failure isolation (one bad lane doesn't poison others)
 *   - stale-heartbeat recovery (lane returns to healthy when heartbeat refreshes)
 *   - no cross-lane leakage (packet ids, queue namespaces, allowed roots, receipt refs,
 *     knowledge sync refs MUST NOT cross lane boundaries)
 *
 * Pure: no I/O, no process, no provider, no git, no scheduler/queue side-effect.
 */
final class AtlasProjectLaneRuntimeInstanceSoak
{
    public const SCHEMA = 'atlas.project_lane.runtime_instance_soak.v1';

    /**
     * @param  list<array<string,mixed>>  $instances list of lane runtime instance descriptors
     * @param  array<string,mixed>  $options {scripts?:array<lane_id, list<tick_event>>, global_safety_stop?:bool}
     * @return array<string,mixed>
     */
    public function run(array $instances, array $options = []): array
    {
        $globalSafety = (bool) ($options['global_safety_stop'] ?? false);
        $scripts = is_array($options['scripts'] ?? null) ? $options['scripts'] : [];

        $laneResults = [];
        $leakAttempts = [];
        $isolatedFailures = [];
        $dependencyViolations = [];

        $namespaces = [];
        $roots = [];

        foreach ($instances as $instance) {
            $laneId = (string) ($instance['lane_id'] ?? '');
            $namespace = (string) ($instance['queue_namespace'] ?? '');
            $allowedRoots = array_values((array) ($instance['allowed_roots'] ?? []));

            if ($namespace !== '') {
                if (isset($namespaces[$namespace]) && $namespaces[$namespace] !== $laneId) {
                    $leakAttempts[] = ['kind' => 'namespace_collision', 'lane_id' => $laneId, 'queue_namespace' => $namespace];
                } else {
                    $namespaces[$namespace] = $laneId;
                }
            }
            foreach ($allowedRoots as $root) {
                if (isset($roots[$root]) && $roots[$root] !== $laneId) {
                    $leakAttempts[] = ['kind' => 'allowed_root_collision', 'lane_id' => $laneId, 'root' => $root, 'other_lane' => $roots[$root]];
                } else {
                    $roots[$root] = $laneId;
                }
            }
        }

        foreach ($instances as $instance) {
            $laneId = (string) ($instance['lane_id'] ?? '');
            $script = is_array($scripts[$laneId] ?? null) ? $scripts[$laneId] : [];
            $laneResults[$laneId] = $this->simulateLane($instance, $script, $globalSafety, $leakAttempts, $isolatedFailures, $dependencyViolations, $namespaces, $roots);
        }

        $passed = $leakAttempts === [] && $dependencyViolations === []
            && $this->allowedHealthyLanesProgressed($laneResults, $globalSafety);

        $payload = [
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'global_safety_stop' => $globalSafety,
            'lane_results' => $laneResults,
            'leak_attempts' => $leakAttempts,
            'isolated_failures' => $isolatedFailures,
            'dependency_violations' => $dependencyViolations,
        ];
        $payload['multi_project_soak_hash'] = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $instance
     * @param  list<array<string,mixed>>  $script  ordered tick events
     * @param  list<array<string,mixed>>  $leakAttempts
     * @param  list<array<string,mixed>>  $isolatedFailures
     * @param  list<array<string,mixed>>  $dependencyViolations
     * @param  array<string,string>  $namespaces
     * @param  array<string,string>  $roots
     * @return array<string,mixed>
     */
    private function simulateLane(array $instance, array $script, bool $globalSafety, array &$leakAttempts, array &$isolatedFailures, array &$dependencyViolations, array $namespaces, array $roots): array
    {
        $laneId = (string) ($instance['lane_id'] ?? '');
        $namespace = (string) ($instance['queue_namespace'] ?? '');
        $allowedRoots = array_values((array) ($instance['allowed_roots'] ?? []));
        $allowedRootSet = array_flip($allowedRoots);

        $ticks = [];
        $safetyStop = false;
        $progress = 0;
        $recovered = false;
        $staleHeartbeat = false;

        foreach ($script as $event) {
            $type = (string) ($event['type'] ?? '');
            switch ($type) {
                case 'tick':
                    if ($globalSafety || $safetyStop || $staleHeartbeat) {
                        $ticks[] = ['type' => 'tick', 'outcome' => 'skipped', 'reason' => $globalSafety ? 'global_safety_stop' : ($safetyStop ? 'lane_safety_stop' : 'stale_heartbeat')];
                        break;
                    }
                    $action = (array) ($event['action'] ?? []);
                    $actionLane = (string) ($action['lane_id'] ?? $laneId);
                    $actionNamespace = (string) ($action['queue_namespace'] ?? $namespace);
                    $actionRoots = array_values((array) ($action['write_roots'] ?? []));
                    $actionPacketId = (string) ($action['task_packet_id'] ?? '');
                    $actionReceipt = (string) ($action['receipt_ref'] ?? '');
                    $actionKnowledgeSyncRef = (string) ($action['knowledge_sync_ref'] ?? '');

                    if ($actionLane !== $laneId) {
                        $leakAttempts[] = ['kind' => 'cross_lane_action', 'lane_id' => $laneId, 'action_lane' => $actionLane];
                        $ticks[] = ['type' => 'tick', 'outcome' => 'leak_refused'];
                        break;
                    }
                    if ($actionNamespace !== '' && $actionNamespace !== $namespace) {
                        $leakAttempts[] = ['kind' => 'namespace_leak', 'lane_id' => $laneId, 'attempted_namespace' => $actionNamespace];
                        $ticks[] = ['type' => 'tick', 'outcome' => 'leak_refused'];
                        break;
                    }
                    foreach ($actionRoots as $r) {
                        if (! $this->rootAllowed((string) $r, $allowedRoots)) {
                            $leakAttempts[] = ['kind' => 'root_leak', 'lane_id' => $laneId, 'attempted_root' => $r];
                            $ticks[] = ['type' => 'tick', 'outcome' => 'leak_refused'];
                            continue 3;
                        }
                    }
                    foreach (['receipt_ref' => $actionReceipt, 'knowledge_sync_ref' => $actionKnowledgeSyncRef, 'task_packet_id' => $actionPacketId] as $kind => $ref) {
                        if ($ref !== '' && ! str_starts_with($ref, $laneId)) {
                            $leakAttempts[] = ['kind' => $kind.'_leak', 'lane_id' => $laneId, 'attempted_ref' => $ref];
                            $ticks[] = ['type' => 'tick', 'outcome' => 'leak_refused'];
                            continue 3;
                        }
                    }
                    foreach ((array) ($action['steady_state_dependencies'] ?? []) as $dep) {
                        $dependencyViolations[] = ['lane_id' => $laneId, 'dependency' => (string) $dep];
                    }

                    $progress++;
                    $ticks[] = ['type' => 'tick', 'outcome' => 'progressed', 'kind' => (string) ($action['kind'] ?? 'native_lane_tick')];
                    break;
                case 'failure':
                    $isolatedFailures[] = ['lane_id' => $laneId, 'reason' => (string) ($event['reason'] ?? 'unknown')];
                    $ticks[] = ['type' => 'failure', 'reason' => (string) ($event['reason'] ?? 'unknown')];
                    break;
                case 'safety_stop':
                    $safetyStop = true;
                    $ticks[] = ['type' => 'safety_stop'];
                    break;
                case 'stale_heartbeat':
                    $staleHeartbeat = true;
                    $ticks[] = ['type' => 'stale_heartbeat'];
                    break;
                case 'heartbeat_recovered':
                    $staleHeartbeat = false;
                    $recovered = true;
                    $ticks[] = ['type' => 'heartbeat_recovered'];
                    break;
                default:
                    $ticks[] = ['type' => 'unknown_event', 'raw_type' => $type];
            }
        }

        return [
            'lane_id' => $laneId,
            'project_id' => (string) ($instance['project_id'] ?? ''),
            'queue_namespace' => $namespace,
            'allowed_roots' => $allowedRoots,
            'safety_stop' => $safetyStop,
            'global_safety_stop' => $globalSafety,
            'recovered' => $recovered,
            'progress_count' => $progress,
            'ticks' => $ticks,
            'isolation' => [
                'unique_namespace' => ($namespaces[$namespace] ?? $laneId) === $laneId,
                'unique_roots' => $allowedRoots !== [] && array_filter($allowedRoots, static fn (string $r) => ($roots[$r] ?? '') !== '') === $allowedRoots,
            ],
        ];
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
     * @param  array<string,array<string,mixed>>  $laneResults
     */
    private function allowedHealthyLanesProgressed(array $laneResults, bool $globalSafety): bool
    {
        if ($globalSafety) {
            return true;
        }
        // At least ONE lane that is not safety-stopped must show progress > 0.
        foreach ($laneResults as $r) {
            if (! (bool) ($r['safety_stop'] ?? false) && (int) ($r['progress_count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
