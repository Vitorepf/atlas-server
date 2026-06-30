<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Project-lane knowledge-sync policy. Builds a PROJECT-SPECIFIC plan from lane manifest + touched paths
 * + outcome facts. Returns required commands as PLAN DATA only — never executes anything.
 *
 * INPUT:
 *   { lane_manifest:{project_id, allowed_scope_roots:list<string>},
 *     touched_paths:list<string>,
 *     outcome_facts?:{requires_release_notes?:bool, integrated_test_passed?:bool} }
 *
 * OUTPUT:
 *   { schema, project_id, conformant:bool, blockers:list<string>, required_commands:list<{id, action, target, reason}> }
 *
 * INVARIANTS:
 *   - Every touched_path MUST live inside the lane's allowed_scope_roots; any escape is a blocker
 *     (cross_project_path:<path>) and the corresponding sync command is OMITTED.
 *   - NO process execution. Pure plan data only.
 *   - DETERMINISTIC envelope: commands sorted by id; blockers sorted byte-stably.
 */
final class AtlasProjectLaneKnowledgeSyncPolicy
{
    public const SCHEMA = 'atlas.multiproject.lane_knowledge_sync_policy.v1';

    /**
     * @param  array{
     *     lane_manifest?:array{project_id?:string, allowed_scope_roots?:list<string>},
     *     touched_paths?:list<string>,
     *     outcome_facts?:array{requires_release_notes?:bool, integrated_test_passed?:bool}
     * }  $facts
     * @return array{schema:string, project_id:string, conformant:bool, blockers:list<string>, required_commands:list<array{id:string, action:string, target:string, reason:string}>}
     */
    public function plan(array $facts): array
    {
        $lane = is_array($facts['lane_manifest'] ?? null) ? $facts['lane_manifest'] : [];
        $projectId = (string) ($lane['project_id'] ?? '');
        $laneRoots = is_array($lane['allowed_scope_roots'] ?? null) ? array_map('strval', $lane['allowed_scope_roots']) : [];
        $touched = is_array($facts['touched_paths'] ?? null) ? array_values(array_map('strval', $facts['touched_paths'])) : [];
        $outcome = is_array($facts['outcome_facts'] ?? null) ? $facts['outcome_facts'] : [];
        $freshness = is_array($facts['freshness_facts'] ?? null) ? $facts['freshness_facts'] : [];
        $staleArtifacts = is_array($freshness['stale'] ?? null) ? array_map('strval', $freshness['stale']) : [];

        $blockers = [];
        if ($projectId === '') {
            $blockers[] = 'missing_project_id';
        }
        if ($laneRoots === []) {
            $blockers[] = 'missing_allowed_scope_roots';
        }
        foreach ($staleArtifacts as $artifact) {
            $blockers[] = 'stale_artifact:'.$artifact;
        }

        // Path containment check.
        $insidePaths = [];
        foreach ($touched as $p) {
            if ($this->insideAnyRoot($p, $laneRoots)) {
                $insidePaths[] = $p;
            } else {
                $blockers[] = 'cross_project_path:'.$p;
            }
        }

        $commands = [];
        $hasDocs = $this->anyMatches($insidePaths, static fn (string $p): bool => str_contains($p, '/docs/') || str_ends_with(strtolower($p), '.md'));
        $hasCode = $this->anyMatches($insidePaths, static fn (string $p): bool => str_ends_with($p, '.php'));

        if ($hasDocs) {
            $commands[] = $this->command(
                'lane-docs-sync:'.$projectId,
                'engineering_knowledge_sync',
                $projectId,
                'docs changed inside lane '.$projectId,
            );
        }
        if ($hasCode) {
            $commands[] = $this->command(
                'lane-code-index:'.$projectId,
                'engineering_knowledge_index_code',
                $projectId,
                'code changed inside lane '.$projectId,
            );
        }
        if (! empty($outcome['integrated_test_passed'])) {
            $commands[] = $this->command(
                'lane-receipts-export:'.$projectId,
                'export_receipts_to_lane',
                $projectId,
                'integrated test passed — receipts must export to lane',
            );
        }
        if (! empty($outcome['requires_release_notes'])) {
            $commands[] = $this->command(
                'lane-memory-export:'.$projectId,
                'export_memory_facts',
                $projectId,
                'release_candidate.requires_release_notes=true',
            );
        }

        usort($commands, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'conformant' => $blockers === [],
            'blockers' => $blockers,
            'required_commands' => $commands,
        ];
    }

    /**
     * @return array{id:string, action:string, target:string, reason:string}
     */
    private function command(string $id, string $action, string $target, string $reason): array
    {
        return ['id' => $id, 'action' => $action, 'target' => $target, 'reason' => $reason];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideAnyRoot(string $path, array $laneRoots): bool
    {
        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $list
     * @param  callable(string):bool  $predicate
     */
    private function anyMatches(array $list, callable $predicate): bool
    {
        foreach ($list as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }
}
