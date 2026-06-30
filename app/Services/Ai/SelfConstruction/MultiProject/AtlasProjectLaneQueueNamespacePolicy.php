<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use RuntimeException;

/**
 * Cross-project task containment: derives a STABLE per-lane task namespace, tag set, and queue key from
 * (project_id, repo_root, mainline_branch). Lets the multi-project queue route packets to the right lane
 * without leaking cross-lane targets.
 *
 * INVARIANTS:
 *   - namespace = lane.<sanitized_project_id>.<repo_root_sha8>.<sanitized_branch>; identical input ⇒
 *     identical namespace.
 *   - REJECTS: empty project_id, unsafe characters in project_id ([^A-Za-z0-9_-]), task_ids that already
 *     carry a DIFFERENT lane namespace prefix (cross-project leakage).
 *   - PRESERVES the shared_local_main_with_scope_lock execution topology.
 *   - Pure: no I/O, no provider call.
 */
final class AtlasProjectLaneQueueNamespacePolicy
{
    public const SCHEMA = 'atlas.multiproject.lane_queue_namespace.v1';

    public const EXECUTION_TOPOLOGY = AtlasProjectLaneAdmissionPolicy::ISOLATION;

    public const NAMESPACE_PREFIX = 'lane.';

    /**
     * @param  array{project_id:string, repo_root:string, mainline_branch:string}  $manifestFacts
     * @return array{schema:string, project_id:string, namespace:string, queue_key:string, tags:list<string>, execution_topology:string}
     */
    public function derive(array $manifestFacts): array
    {
        $projectId = trim((string) ($manifestFacts['project_id'] ?? ''));
        $repoRoot = trim((string) ($manifestFacts['repo_root'] ?? ''));
        $mainlineRaw = trim((string) ($manifestFacts['mainline_branch'] ?? ''));

        if ($projectId === '') {
            throw new RuntimeException('queue namespace policy: empty project_id');
        }
        if (! preg_match('/^[A-Za-z0-9_\-]+$/', $projectId)) {
            throw new RuntimeException('queue namespace policy: unsafe characters in project_id: '.$projectId);
        }
        if ($repoRoot === '') {
            throw new RuntimeException('queue namespace policy: empty repo_root');
        }
        if (! str_starts_with($repoRoot, '/')) {
            throw new RuntimeException('queue namespace policy: repo_root must be an absolute path: '.$repoRoot);
        }
        if ($repoRoot === '/') {
            throw new RuntimeException('queue namespace policy: repo_root must not be filesystem root /');
        }
        if (str_contains($repoRoot, '..')) {
            throw new RuntimeException('queue namespace policy: repo_root must not contain traversal: '.$repoRoot);
        }
        if ($mainlineRaw === '') {
            throw new RuntimeException('queue namespace policy: empty mainline_branch');
        }

        $repoHash = substr(hash('sha256', $repoRoot), 0, 8);
        $branchSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $mainlineRaw) ?? '';
        if ($branchSafe === '') {
            throw new RuntimeException('queue namespace policy: mainline_branch sanitizes to empty namespace segment: '.$mainlineRaw);
        }
        $namespace = self::NAMESPACE_PREFIX.$projectId.'.'.$repoHash.'.'.$branchSafe;
        $queueKey = 'queue.'.$namespace;

        return [
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'namespace' => $namespace,
            'queue_key' => $queueKey,
            'tags' => [
                'lane:'.$projectId,
                'repo_root_sha8:'.$repoHash,
                'mainline:'.$branchSafe,
            ],
            'execution_topology' => self::EXECUTION_TOPOLOGY,
        ];
    }

    /**
     * Returns a namespaced task_id. If the input already carries a lane prefix that DIFFERS from the
     * derived namespace, throws — that's cross-project leakage.
     *
     * @param  array{namespace:string}  $namespaceFacts  from derive()
     */
    public function namespacedTaskId(string $taskId, array $namespaceFacts): string
    {
        $namespace = (string) ($namespaceFacts['namespace'] ?? '');
        if ($namespace === '') {
            throw new RuntimeException('queue namespace policy: missing namespace in facts');
        }
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('queue namespace policy: empty task_id');
        }
        if (str_starts_with($taskId, self::NAMESPACE_PREFIX)) {
            // Already namespaced. Ensure it belongs to the SAME lane (or refuse).
            $dot = strpos($taskId, ':');
            if ($dot === false) {
                throw new RuntimeException('queue namespace policy: malformed namespaced task_id: '.$taskId);
            }
            $prefix = substr($taskId, 0, $dot);
            if ($prefix !== $namespace) {
                throw new RuntimeException('queue namespace policy: cross-lane refusal — task_id namespace '.$prefix.' != lane '.$namespace);
            }
            if (substr($taskId, $dot + 1) === '') {
                throw new RuntimeException('queue namespace policy: lane-prefix smuggling — no task segment after namespace: '.$taskId);
            }

            return $taskId;
        }

        return $namespace.':'.$taskId;
    }
}
