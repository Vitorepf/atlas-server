<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure planner: turns an accepted simplification wave (organs merged or deleted)
 * into the docs/memory/code-index/capability-map sync actions required so the
 * brain does not keep believing the old organs still exist.
 *
 * A wave with no merged organs is a no-op — no sync churn. A wave not marked safe
 * (behavior equivalence not proven) is blocked from sync entirely: never propagate
 * knowledge about a merge that hasn't been proven behavior-preserving.
 *
 * Pure / deterministic. No I/O — callers persist the returned sync_actions.
 */
final class AtlasSelfConstructionPostRefactorKnowledgeSyncPlan
{
    public const SCHEMA = 'atlas.self_construction.post_refactor_knowledge_sync_plan.v1';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_NO_OP = 'no_op';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    private const SYNC_TARGETS = ['docs', 'memory', 'code_index', 'capability_map'];

    /**
     * @param  array{
     *   wave_id?: string,
     *   safe?: bool,
     *   merged_organs?: list<array{name?: string, old_paths?: list<string>, new_path?: string}>,
     * }  $wave
     * @return array{schema:string, status:string, sync_actions:list<array<string,mixed>>, blockers:list<string>}
     */
    public function plan(array $wave): array
    {
        $waveId = (string) ($wave['wave_id'] ?? '');
        $safe = (bool) ($wave['safe'] ?? false);
        $mergedOrgans = array_values((array) ($wave['merged_organs'] ?? []));

        if ($mergedOrgans === []) {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_NO_OP,
                'sync_actions' => [],
                'blockers' => [],
            ];
        }

        if (! $safe) {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'sync_actions' => [],
                'blockers' => ['wave_not_marked_safe'],
            ];
        }

        $syncActions = [];
        foreach ($mergedOrgans as $organ) {
            $name = (string) ($organ['name'] ?? '');
            $oldPaths = array_values((array) ($organ['old_paths'] ?? []));
            $newPath = (string) ($organ['new_path'] ?? '');

            foreach (self::SYNC_TARGETS as $target) {
                $syncActions[] = [
                    'wave_id' => $waveId,
                    'target' => $target,
                    'organ' => $name,
                    'old_paths' => $oldPaths,
                    'new_path' => $newPath,
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_SYNCED,
            'sync_actions' => $syncActions,
            'blockers' => [],
        ];
    }
}
