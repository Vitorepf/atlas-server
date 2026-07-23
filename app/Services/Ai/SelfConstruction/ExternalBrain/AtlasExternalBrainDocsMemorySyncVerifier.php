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

    private const DEFAULT_MAX_ARTIFACT_AGE_SECONDS = 86400;

    /** artifact name => command hint for the smallest follow-up that resyncs it. */
    private const SYNC_COMMAND_HINTS = [
        'docs' => 'update docs/engineering-knowledge-base then run: atlas engineering knowledge sync --prune',
        'memory' => 'record the memory outcome for this change (atlas memory:record or equivalent)',
        'code_index' => 'atlas engineering knowledge index-code --prune',
    ];

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
        $publicContractChanged = (bool) ($change['public_contract_changed'] ?? false);

        $staleSurfaces = [];
        $requiredActions = [];
        $missingArtifacts = [];
        $staleArtifacts = [];

        // A public contract change must sync docs/memory even when internally small
        // (is_internal_refactor) — only pure test-only edits are exempt.
        $knowledgeSurfaceApplies = $behaviorChanged && ! $isTestOnly
            && ($publicContractChanged || ! $isInternalRefactor);

        $nowUnix = (int) ($change['now_unix'] ?? time());
        $maxAgeSeconds = (int) ($change['max_artifact_age_seconds'] ?? self::DEFAULT_MAX_ARTIFACT_AGE_SECONDS);

        // AC1/AC2: distinguishes MISSING (never produced) from STALE (produced, but the
        // recorded timestamp is older than the freshness window) — fresh_context must block on
        // either, but callers get a precise remediation target instead of one flat "stale" bucket.
        $classifyArtifact = function (string $name, bool $present, mixed $syncedAt) use (&$missingArtifacts, &$staleArtifacts, $nowUnix, $maxAgeSeconds): bool {
            if (! $present) {
                $missingArtifacts[] = $name;

                return false;
            }
            if ($syncedAt !== null && ($nowUnix - (int) $syncedAt) > $maxAgeSeconds) {
                $staleArtifacts[] = $name;

                return false;
            }

            return true;
        };

        if ($knowledgeSurfaceApplies) {
            $docsFresh = $classifyArtifact('docs', $docsUpdated, $change['docs_synced_at'] ?? null);
            if (! $docsFresh) {
                $staleSurfaces[] = 'docs';
                $requiredActions[] = 'docs_patch';
            }
            $memoryFresh = $classifyArtifact('memory', $memoryFactsRecorded, $change['memory_recorded_at'] ?? null);
            if (! $memoryFresh) {
                $staleSurfaces[] = 'memory';
                $requiredActions[] = 'memory_outcome_record';
            }
        }

        $codeIndexArtifactFresh = $classifyArtifact('code_index', $codeIndexFresh, $change['code_index_indexed_at'] ?? null);
        if (! $codeIndexArtifactFresh) {
            $staleSurfaces[] = 'code_index';
            $requiredActions[] = 'index_code_refresh';
        }

        $syncStatus = match (true) {
            $staleSurfaces === [] => self::STATUS_NO_ACTION,
            in_array('docs', $staleSurfaces, true) || in_array('memory', $staleSurfaces, true) => self::STATUS_KNOWLEDGE_STALE,
            default => self::STATUS_INDEX_STALE,
        };

        $missingArtifacts = array_values(array_unique($missingArtifacts));
        $staleArtifacts = array_values(array_unique($staleArtifacts));
        $freshContext = $missingArtifacts === [] && $staleArtifacts === [];

        $syncCommandHints = [];
        foreach (array_unique(array_merge($missingArtifacts, $staleArtifacts)) as $artifact) {
            if (isset(self::SYNC_COMMAND_HINTS[$artifact])) {
                $syncCommandHints[] = self::SYNC_COMMAND_HINTS[$artifact];
            }
        }

        $smallestFollowUp = $this->smallestFollowUp($requiredActions, $syncStatus);

        return [
            'schema' => self::SCHEMA,
            'sync_status' => $syncStatus,
            'required_actions' => array_values(array_unique($requiredActions)),
            'stale_surfaces' => array_values(array_unique($staleSurfaces)),
            'fresh_context' => $freshContext,
            'missing_artifacts' => $missingArtifacts,
            'stale_artifacts' => $staleArtifacts,
            'sync_command_hints' => $syncCommandHints,
            'smallest_follow_up' => $smallestFollowUp,
            'evidence' => [
                'behavior_changed='.($behaviorChanged ? 'true' : 'false'),
                'is_test_only='.($isTestOnly ? 'true' : 'false'),
                'is_internal_refactor='.($isInternalRefactor ? 'true' : 'false'),
                'public_contract_changed='.($publicContractChanged ? 'true' : 'false'),
                'docs_updated='.($docsUpdated ? 'true' : 'false'),
                'memory_facts_recorded='.($memoryFactsRecorded ? 'true' : 'false'),
                'code_index_fresh='.($codeIndexFresh ? 'true' : 'false'),
            ],
        ];
    }

    /**
     * Compute the smallest follow-up action from the required actions.
     *
     * @param  list<string>  $requiredActions
     * @return string
     */
    private function smallestFollowUp(array $requiredActions, string $syncStatus): string
    {
        if ($syncStatus === self::STATUS_NO_ACTION) {
            return 'no_follow_up_needed';
        }

        if ($requiredActions === []) {
            return 'no_follow_up_needed';
        }

        // Pick the smallest single action — index refresh is cheapest, then docs, then memory.
        $priority = [
            'index_code_refresh' => 1,
            'docs_patch' => 2,
            'memory_outcome_record' => 3,
        ];

        $smallest = null;
        $lowestPriority = PHP_INT_MAX;
        foreach ($requiredActions as $action) {
            $p = $priority[$action] ?? 99;
            if ($p < $lowestPriority) {
                $lowestPriority = $p;
                $smallest = $action;
            }
        }

        return $smallest ?? 'unknown_action';
    }
}
