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
    /** A dependency is SATISFIED only after its dry-run completion is recorded. */
    public const DEPENDENCY_SATISFIED_STATES = ['completed_dry_run'];

    /** A dependency here is unmet and requires operator repair before its dependent can run. */
    public const DEPENDENCY_DEAD_STATES = ['blocked', 'cancelled'];

    /** Full-task classification verdicts. */
    public const VERDICT_READY = 'ready';

    public const VERDICT_WAITING = 'waiting';

    public const VERDICT_BLOCKED = 'blocked';

    public const VERDICT_POISON = 'poison';

    public const VERDICT_OPERATOR_ONLY = 'operator_only';

    /** @var list<string> Patterns that mark an acceptance as impossible / contradictory. */
    private const IMPOSSIBLE_ACCEPTANCE_PATTERNS = [
        'contradictory',
        'impossible',
        'paradox',
        'circular',
        'self-contradict',
        'simultaneously',
        'and fail',
        'pass and fail',
    ];

    /** @var list<string> Markers that a task requires human-only action. */
    private const OPERATOR_ONLY_MARKERS = [
        'requires_human',
        'human_only',
        'manual_approval',
        'operator_decision',
        'requires_human_review',
        'human_gate',
    ];

    /** @var list<string> Markers that a packet is test-only (no implementation file). */
    private const TEST_ONLY_MARKERS = [
        'test_only',
        'test-only',
        'feature_test_only',
    ];

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
                return 'blocked'; // Missing prerequisite ⇒ fail closed until the dependency graph is repaired.
            }
            if (in_array($node['status'], self::DEPENDENCY_SATISFIED_STATES, true)) {
                continue; // Recorded completion ⇒ satisfied.
            }
            if ($rootId !== '' && self::dependencyReaches($nodeLoader, $depId, $rootId, $cache, [])) {
                return 'blocked'; // Cycle ⇒ no valid execution order exists.
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
     * Full-task classification using concrete packet + queue evidence.
     *
     * Precedence: operator_only > poison > blocked > waiting > ready.
     *   - operator_only: task metadata carries an operator-only marker.
     *   - poison: test-only packet, forbidden self-target, or impossible acceptance.
     *   - blocked: upstream dependency is dead (blocked/quarantined), or allowed_files
     *     insufficient for the acceptance to be runnable.
     *   - waiting: upstream dependency is inflight (claimable but not completed).
     *   - ready: all deps satisfied, allowed_files sufficient, acceptance runnable.
     *
     * @param  Closure(string):?array  $nodeLoader
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @return array{verdict:string, reason:string}
     */
    public static function classify(Closure $nodeLoader, array $candidate, array &$cache): array
    {
        $rootId = (string) ($candidate['task_packet_id'] ?? '');
        $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
        $allowedFiles = array_values(array_filter((array) ($candidate['allowed_files'] ?? []), 'is_string'));
        $acceptanceCriteria = array_values(array_filter((array) ($candidate['acceptance_criteria'] ?? []), 'is_string'));
        $packetQuality = is_array($candidate['packet_quality'] ?? null) ? $candidate['packet_quality'] : [];
        $qualityFacts = is_array($packetQuality['facts'] ?? null) ? $packetQuality['facts'] : [];
        $qualityDeficiencies = is_array($packetQuality['deficiencies'] ?? null) ? $packetQuality['deficiencies'] : [];

        // OPERATOR_ONLY: explicit human-only markers.
        if (self::candidateHasMarker($metadata, $candidate, self::OPERATOR_ONLY_MARKERS)) {
            return ['verdict' => self::VERDICT_OPERATOR_ONLY, 'reason' => 'requires_human_action'];
        }

        // POISON: forbidden self-target.
        if (! empty($qualityFacts['forbidden_self_targets']) || in_array('forbidden_self_target', $qualityDeficiencies, true)) {
            return ['verdict' => self::VERDICT_POISON, 'reason' => 'forbidden_self_target'];
        }

        // POISON: test-only packet with no implementation file (all allowed_files are test paths).
        if ($allowedFiles !== [] && self::isTestOnlyPacket($allowedFiles)) {
            return ['verdict' => self::VERDICT_POISON, 'reason' => 'test_only_packet_no_implementation'];
        }
        // Also detect explicit test_only markers in packet quality.
        if (self::candidateHasMarker($metadata, $candidate, self::TEST_ONLY_MARKERS) || in_array('test_only', $qualityDeficiencies, true)) {
            return ['verdict' => self::VERDICT_POISON, 'reason' => 'test_only_packet_no_implementation'];
        }

        // POISON: impossible / contradictory acceptance.
        if (self::acceptanceIsImpossible($acceptanceCriteria, $qualityDeficiencies)) {
            return ['verdict' => self::VERDICT_POISON, 'reason' => 'impossible_or_contradictory_acceptance'];
        }

        // BLOCKED: upstream dependency is dead.
        $depVerdict = self::classifyDependencies($nodeLoader, $candidate, $cache);
        if ($depVerdict === 'blocked') {
            return ['verdict' => self::VERDICT_BLOCKED, 'reason' => 'upstream_dependency_blocked'];
        }

        // BLOCKED: allowed_files insufficient for acceptance to be runnable.
        if (! self::acceptanceIsRunnable($acceptanceCriteria, $allowedFiles)) {
            return ['verdict' => self::VERDICT_BLOCKED, 'reason' => 'allowed_files_insufficient_for_acceptance'];
        }

        // WAITING: upstream dependency is inflight.
        if ($depVerdict === 'inflight') {
            return ['verdict' => self::VERDICT_WAITING, 'reason' => 'upstream_dependency_inflight'];
        }

        // READY: all deps met, allowed_files sufficient, acceptance runnable.
        return ['verdict' => self::VERDICT_READY, 'reason' => 'dependencies_met_scope_sufficient_acceptance_runnable'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $markers
     */
    private static function candidateHasMarker(array $metadata, array $candidate, array $markers): bool
    {
        $haystacks = [
            json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            (string) ($candidate['objective'] ?? ''),
        ];
        foreach ($haystacks as $hay) {
            $lower = strtolower($hay);
            foreach ($markers as $marker) {
                if (str_contains($lower, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private static function isTestOnlyPacket(array $allowedFiles): bool
    {
        foreach ($allowedFiles as $file) {
            $norm = str_replace('\\', '/', $file);
            if (! (str_starts_with($norm, 'tests/')
                || str_contains($norm, '/tests/')
                || str_ends_with($norm, 'Test.php')
                || str_ends_with($norm, '.test.php')
                || str_ends_with($norm, 'Test.bs.php')
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $qualityDeficiencies
     */
    private static function acceptanceIsImpossible(array $acceptanceCriteria, array $qualityDeficiencies): bool
    {
        if (in_array('impossible_acceptance', $qualityDeficiencies, true)
            || in_array('contradictory_acceptance', $qualityDeficiencies, true)
        ) {
            return true;
        }
        foreach ($acceptanceCriteria as $criterion) {
            $lower = strtolower((string) $criterion);
            foreach (self::IMPOSSIBLE_ACCEPTANCE_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $allowedFiles
     */
    private static function acceptanceIsRunnable(array $acceptanceCriteria, array $allowedFiles): bool
    {
        // Acceptance is runnable when there is at least one runnable criterion AND at least
        // one non-test implementation file to make it true.
        if ($acceptanceCriteria === []) {
            return false;
        }
        $hasRunnable = false;
        foreach ($acceptanceCriteria as $criterion) {
            $lower = strtolower((string) $criterion);
            if (str_contains($lower, 'phpunit')
                || str_contains($lower, 'artisan test')
                || str_contains($lower, 'pest')
                || str_contains($lower, 'exits 0')
                || str_contains($lower, 'passes')
                || str_contains($lower, 'gate')
            ) {
                $hasRunnable = true;
                break;
            }
        }
        if (! $hasRunnable) {
            return false;
        }

        // Need at least one implementation file (not purely test paths) for the acceptance to be real.
        foreach ($allowedFiles as $file) {
            $norm = str_replace('\\', '/', $file);
            if (! (str_starts_with($norm, 'tests/')
                || str_contains($norm, '/tests/')
                || str_ends_with($norm, 'Test.php')
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Structured explanation of classifyDependencies()'s verdict: which depends_on ids
     * are inflight, blocked (dead), absent (fail-open), or cycle-broken (fail-open).
     *
     * @param  Closure(string):?array  $nodeLoader
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @return array{verdict:string, inflight_dependency_ids:list<string>, blocked_dependency_ids:list<string>, absent_dependency_ids:list<string>, cycle_broken_dependency_ids:list<string>}
     */
    public static function explainDependencies(Closure $nodeLoader, array $candidate, array &$cache): array
    {
        $rootId = (string) ($candidate['task_packet_id'] ?? '');
        $dependsOn = array_values(array_filter((array) data_get($candidate, 'metadata.depends_on', []), 'is_string'));

        $inflight = [];
        $blocked = [];
        $absent = [];
        $cycleBroken = [];

        foreach ($dependsOn as $depId) {
            $node = self::dependencyNode($nodeLoader, $depId, $cache);
            if ($node === null) {
                $absent[] = $depId;

                continue;
            }
            if (in_array($node['status'], self::DEPENDENCY_SATISFIED_STATES, true)) {
                continue;
            }
            if ($rootId !== '' && self::dependencyReaches($nodeLoader, $depId, $rootId, $cache, [])) {
                $cycleBroken[] = $depId;

                continue;
            }
            if (in_array($node['status'], self::DEPENDENCY_DEAD_STATES, true)) {
                $blocked[] = $depId;
            } else {
                $inflight[] = $depId;
            }
        }

        $verdict = $inflight !== [] ? 'inflight' : ($blocked !== [] ? 'blocked' : 'met');

        return [
            'verdict' => $verdict,
            'inflight_dependency_ids' => $inflight,
            'blocked_dependency_ids' => $blocked,
            'absent_dependency_ids' => $absent,
            'cycle_broken_dependency_ids' => $cycleBroken,
        ];
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
