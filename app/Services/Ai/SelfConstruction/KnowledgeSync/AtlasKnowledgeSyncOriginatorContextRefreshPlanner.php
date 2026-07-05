<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Plans the minimal knowledge sync actions needed before an originator
 * trusts docs, code index and task outcome read models.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasKnowledgeSyncOriginatorContextRefreshPlanner
{
    public const SCHEMA = 'atlas.self_construction.knowledge_sync_originator_context_refresh_planner.v1';

    public const ACTION_SYNC_DOCS = 'sync_docs';
    public const ACTION_INDEX_CODE = 'index_code';
    public const ACTION_SYNC_TASK_OUTCOMES = 'sync_task_outcomes';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $actions = [];

        $docsSynced = (bool) ($input['docs_synced'] ?? true);
        $codeIndexFresh = (bool) ($input['code_index_fresh'] ?? true);
        $taskOutcomesFresh = (bool) ($input['task_outcomes_fresh'] ?? true);

        if (! $docsSynced) {
            $actions[] = ['action' => self::ACTION_SYNC_DOCS, 'reason' => 'docs_not_synced'];
        }
        if (! $codeIndexFresh) {
            $actions[] = ['action' => self::ACTION_INDEX_CODE, 'reason' => 'code_index_stale'];
        }
        if (! $taskOutcomesFresh) {
            $actions[] = ['action' => self::ACTION_SYNC_TASK_OUTCOMES, 'reason' => 'task_outcomes_stale'];
        }

        return [
            'schema' => self::SCHEMA,
            'actions' => $actions,
            'action_count' => count($actions),
            'ready' => $actions === [],
            'docs_synced' => $docsSynced,
            'code_index_fresh' => $codeIndexFresh,
            'task_outcomes_fresh' => $taskOutcomesFresh,
        ];
    }
}
