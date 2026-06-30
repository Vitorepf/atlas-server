<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, facts-only verifier that a self-construction change left no STALE knowledge behind. A change that
 * altered behavior but did not update docs or record a memory outcome reads as knowledge_stale; a change
 * that only touched tests or was a pure internal refactor (no public behavior change) reads as no_action;
 * a code-index freshness gap is reported on its own (index_stale) — never folded into "docs are stale"
 * when docs were not actually the problem. Recommends the SMALLEST follow-up, not a blanket re-sync.
 *
 * Input change shape:
 *   {behavior_changed:bool, docs_updated?:bool, memory_facts_recorded?:bool, code_index_fresh?:bool,
 *    is_test_only?:bool, is_internal_refactor?:bool}
 */
final class AtlasExternalBrainDocsMemorySyncVerifier
{
    public const SCHEMA = 'atlas.self_construction.external_brain.docs_memory_sync_verifier.v1';

    public const STATUS_NO_ACTION = 'no_action';

    public const STATUS_KNOWLEDGE_STALE = 'knowledge_stale';

    public const STATUS_INDEX_STALE = 'index_stale';

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public function verify(array $change): array
    {
        $behaviorChanged = (bool) ($change['behavior_changed'] ?? false);
        $docsUpdated = (bool) ($change['docs_updated'] ?? false);
        $memoryFactsRecorded = (bool) ($change['memory_facts_recorded'] ?? false);
        $codeIndexFresh = (bool) ($change['code_index_fresh'] ?? true);
        $isTestOnly = (bool) ($change['is_test_only'] ?? false);
        $isInternalRefactor = (bool) ($change['is_internal_refactor'] ?? false);

        $staleSurfaces = [];
        $requiredActions = [];

        $knowledgeSurfaceApplies = $behaviorChanged && ! $isTestOnly && ! $isInternalRefactor;

        if ($knowledgeSurfaceApplies) {
            if (! $docsUpdated) {
                $staleSurfaces[] = 'docs';
                $requiredActions[] = 'docs_patch';
            }
            if (! $memoryFactsRecorded) {
                $staleSurfaces[] = 'memory';
                $requiredActions[] = 'memory_outcome_record';
            }
        }

        if (! $codeIndexFresh) {
            $staleSurfaces[] = 'code_index';
            $requiredActions[] = 'index_code_refresh';
        }

        $syncStatus = match (true) {
            $staleSurfaces === [] => self::STATUS_NO_ACTION,
            in_array('docs', $staleSurfaces, true) || in_array('memory', $staleSurfaces, true) => self::STATUS_KNOWLEDGE_STALE,
            default => self::STATUS_INDEX_STALE,
        };

        return [
            'schema' => self::SCHEMA,
            'sync_status' => $syncStatus,
            'required_actions' => array_values(array_unique($requiredActions)),
            'stale_surfaces' => array_values(array_unique($staleSurfaces)),
            'evidence' => [
                'behavior_changed='.($behaviorChanged ? 'true' : 'false'),
                'is_test_only='.($isTestOnly ? 'true' : 'false'),
                'is_internal_refactor='.($isInternalRefactor ? 'true' : 'false'),
                'docs_updated='.($docsUpdated ? 'true' : 'false'),
                'memory_facts_recorded='.($memoryFactsRecorded ? 'true' : 'false'),
                'code_index_fresh='.($codeIndexFresh ? 'true' : 'false'),
            ],
        ];
    }
}
