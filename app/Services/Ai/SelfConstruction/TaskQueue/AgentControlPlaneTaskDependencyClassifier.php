<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

use Closure;

/**
 * Dependency classification for the Agent Control Plane task queue orchestrator.
 *
 * Extracted from AgentControlPlaneTaskQueueOrchestrator to reduce the
 * god-class. Pure functions over a node-loader closure (the orchestrator
 * passes its queue repository wrapped) and an in-memory cache.
 */
final class AgentControlPlaneTaskDependencyClassifier
{
    /** A dep in one of these states is SATISFIED: completed, or terminally GONE (cancelled ⇒ fail-open). */
    public const DEPENDENCY_SATISFIED_STATES = ['completed_dry_run', 'cancelled'];

    /** A dep here is unmet but DEAD (quarantined) — operator-recoverable, NOT the advancing ladder. */
    public const DEPENDENCY_DEAD_STATES = ['blocked'];

    /**
     * Classify a candidate's depends_on into the gate/wait verdict.
     *
     * @param  Closure(string):?array  $nodeLoader  Returns the raw queue record (status + metadata)
     *                                          or null when absent. Cached internally per call.
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     */
    public static function classifyDependencies(Closure $nodeLoader, array $candidate, array &$cache): string
    {
        $rootId = (string) ($candidate['task_packet_id'] ?? '');
        $dependsOn = array_values(array_filter((array) data_get($candidate, 'metadata.depends_on', []), 'is_string'));
        $sawInflight = false;
        $sawDead = false;
        foreach ($dependsOn as $depId) {
            $node = self::dependencyNode($nodeLoader, $depId, $cache);
            if ($node === null) {
                continue; // absent ⇒ fail-open (satisfied).
            }
            if (in_array($node['status'], self::DEPENDENCY_SATISFIED_STATES, true)) {
                continue; // completed OR cancelled ⇒ satisfied.
            }
            if ($rootId !== '' && self::dependencyReaches($nodeLoader, $depId, $rootId, $cache, [])) {
                continue; // CYCLE ⇒ fail-open (break the deadlock).
            }
            if (in_array($node['status'], self::DEPENDENCY_DEAD_STATES, true)) {
                $sawDead = true;
            } else {
                $sawInflight = true;
            }
        }

        if ($sawInflight) {
            return 'inflight';
        }

        return $sawDead ? 'blocked' : 'met';
    }

    /**
     * @param  Closure(string):?array  $nodeLoader
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @return array{status:string, depends_on:list<string>}|null
     */
    public static function dependencyNode(Closure $nodeLoader, string $id, array &$cache): ?array
    {
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $record = $nodeLoader($id);

        return $cache[$id] = $record === null ? null : [
            'status' => (string) ($record['status'] ?? ''),
            'depends_on' => array_values(array_filter((array) data_get($record, 'metadata.depends_on', []), 'is_string')),
        ];
    }

    /**
     * @param  Closure(string):?array  $nodeLoader
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @param  array<string, bool>  $seen
     */
    public static function dependencyReaches(Closure $nodeLoader, string $fromId, string $targetId, array &$cache, array $seen): bool
    {
        if (isset($seen[$fromId])) {
            return false;
        }
        $seen[$fromId] = true;
        $node = self::dependencyNode($nodeLoader, $fromId, $cache);
        if ($node === null) {
            return false;
        }
        foreach ($node['depends_on'] as $next) {
            if ($next === $targetId || self::dependencyReaches($nodeLoader, $next, $targetId, $cache, $seen)) {
                return true;
            }
        }

        return false;
    }
}
