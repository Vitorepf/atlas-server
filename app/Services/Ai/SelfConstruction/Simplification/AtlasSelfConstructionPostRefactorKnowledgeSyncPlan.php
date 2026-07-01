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
 * MINIMAL SYNC (per organ, opt-in facts default to true so existing callers that never set
 * them keep getting the full four-target sync they already rely on):
 *   code_index  — ALWAYS included; the file(s) mechanically moved, the index must reflect that.
 *   docs        — included only when behavior_changed (default true).
 *   memory      — included only when ownership_changed (default true).
 *   capability_map — included only when ownership_changed (default true).
 * A behavior-neutral, ownership-unchanged merge therefore syncs only code_index — never a
 * broad four-target sync for a mechanical rename.
 *
 * STALE-KNOWLEDGE RISK: when the wave supplies a post_sync_knowledge_snapshot naming organs
 * still mentioned in docs/memory after the wave, any merged/deleted organ found there is
 * reported as a stale_knowledge_risk — the brain must never keep believing a retired organ
 * still exists.
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
            $behaviorChanged = (bool) ($organ['behavior_changed'] ?? true);
            $ownershipChanged = (bool) ($organ['ownership_changed'] ?? true);

            $targets = ['code_index'];
            if ($behaviorChanged) {
                $targets[] = 'docs';
            }
            if ($ownershipChanged) {
                $targets[] = 'memory';
                $targets[] = 'capability_map';
            }

            foreach (self::SYNC_TARGETS as $target) {
                if (! in_array($target, $targets, true)) {
                    continue;
                }
                $syncActions[] = [
                    'wave_id' => $waveId,
                    'target' => $target,
                    'organ' => $name,
                    'old_paths' => $oldPaths,
                    'new_path' => $newPath,
                ];
            }
        }

        $staleKnowledgeRisks = $this->detectStaleKnowledgeRisks($mergedOrgans, (array) ($wave['post_sync_knowledge_snapshot'] ?? []));

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_SYNCED,
            'sync_actions' => $syncActions,
            'blockers' => [],
            'stale_knowledge_risks' => $staleKnowledgeRisks,
        ];
    }

    /**
     * Flags merged/deleted organs still mentioned in a post-sync docs/memory snapshot — proof
     * that the brain still believes a retired organ exists.
     *
     * @param  list<array<string,mixed>>  $mergedOrgans
     * @param  array{docs_mentions?: list<string>, memory_mentions?: list<string>}  $snapshot
     * @return list<array{organ:string, source:string}>
     */
    private function detectStaleKnowledgeRisks(array $mergedOrgans, array $snapshot): array
    {
        $docsMentions = array_values(array_map('strval', (array) ($snapshot['docs_mentions'] ?? [])));
        $memoryMentions = array_values(array_map('strval', (array) ($snapshot['memory_mentions'] ?? [])));

        $risks = [];
        foreach ($mergedOrgans as $organ) {
            $name = (string) ($organ['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (in_array($name, $docsMentions, true)) {
                $risks[] = ['organ' => $name, 'source' => 'docs'];
            }
            if (in_array($name, $memoryMentions, true)) {
                $risks[] = ['organ' => $name, 'source' => 'memory'];
            }
        }

        return $risks;
    }
}
