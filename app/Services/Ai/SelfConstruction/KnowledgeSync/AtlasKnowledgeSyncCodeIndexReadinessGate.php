<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure FACTS-only gate. Reports whether the Atlas code intelligence index is fresh enough to be
 * trusted as the post-change knowledge surface after Self-Construction edits.
 *
 * Inputs:
 *   manifest: {
 *     workspace_id: string,
 *     required_artifacts: list<string>           // e.g. ['atlas_code_symbols','atlas_code_files']
 *     max_age_seconds?: int                       // freshness window, default 1800s
 *     docs_only_bypass?: bool                     // when true and changed_code_hash absent, bypass code-index gate
 *   }
 *   observations: {
 *     now_unix: int,
 *     indexed_at_unix?: int,                      // last successful atlas:engineering:knowledge:index-code run
 *     index_code_status?: 'pass'|'fail'|'unknown',
 *     observed_artifacts?: list<string>,           // tables/symbols the local schema actually has
 *     local_schema_available?: bool,
 *     changed_code_hash?: string,                  // sha of code that should be reflected in the index
 *     index_hash?: string,                         // sha currently represented in the index
 *   }
 *
 * Output (facts only):
 *   {schema_version, workspace_id, ready, blockers, degraded_but_blocked, observed_at}
 *
 * NEVER reads the filesystem, NEVER touches a DB, NEVER calls a provider. The CALLER passes the
 * observations. NO numeric score.
 */
final class AtlasKnowledgeSyncCodeIndexReadinessGate
{
    public const SCHEMA = 'atlas.knowledge_sync.code_index_readiness.v1';

    public const DEFAULT_MAX_AGE_SECONDS = 1800;

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $observations
     * @return array<string,mixed>
     */
    public function evaluate(array $manifest, array $observations): array
    {
        $now = (int) ($observations['now_unix'] ?? 0);
        $maxAge = (int) ($manifest['max_age_seconds'] ?? self::DEFAULT_MAX_AGE_SECONDS);
        $workspaceId = (string) ($manifest['workspace_id'] ?? '');
        $requiredArtifacts = array_values((array) ($manifest['required_artifacts'] ?? []));
        $observedArtifacts = array_values((array) ($observations['observed_artifacts'] ?? []));
        $localSchemaAvailable = (bool) ($observations['local_schema_available'] ?? false);
        $docsOnlyBypass = (bool) ($manifest['docs_only_bypass'] ?? false);
        $changedCodeHash = (string) ($observations['changed_code_hash'] ?? '');

        $blockers = [];
        $degradedBlocked = [];

        if ($workspaceId === '') {
            $blockers[] = 'workspace_id_missing';
        }

        if ($requiredArtifacts === []) {
            $blockers[] = 'required_artifacts_empty';
        }

        if (! $localSchemaAvailable) {
            $degradedBlocked[] = 'local_schema_unavailable';
            $blockers[] = 'degraded_but_blocked:local_schema_unavailable';
        }

        // Docs-only bypass: when there's no changed code hash to reconcile, we may skip the code-index
        // sub-checks (the change was docs-only). The bypass is EXPLICIT — never inferred.
        if ($docsOnlyBypass && $changedCodeHash === '') {
            return $this->envelope($workspaceId, $blockers, $degradedBlocked, $now, bypassed: true);
        }

        $missingTables = [];
        foreach ($requiredArtifacts as $artifact) {
            if (! in_array($artifact, $observedArtifacts, true)) {
                $missingTables[] = $artifact;
            }
        }
        if ($missingTables !== []) {
            $blockers[] = 'code_index_tables_missing:'.implode(',', $missingTables);
        }

        $indexedAt = $observations['indexed_at_unix'] ?? null;
        if (! is_int($indexedAt)) {
            $blockers[] = 'indexed_at_missing';
        } elseif ($now - $indexedAt > $maxAge) {
            $blockers[] = 'index_stale';
        }

        $indexStatus = (string) ($observations['index_code_status'] ?? 'unknown');
        if ($indexStatus === 'fail') {
            $blockers[] = 'index_code_run_failed';
        } elseif ($indexStatus === 'unknown') {
            $blockers[] = 'index_code_status_unknown';
        }

        if ($changedCodeHash !== '') {
            $indexHash = (string) ($observations['index_hash'] ?? '');
            if ($indexHash === '') {
                $blockers[] = 'index_hash_missing';
            } elseif ($indexHash !== $changedCodeHash) {
                $blockers[] = 'changed_code_hash_not_represented';
            }
        }

        return $this->envelope($workspaceId, $blockers, $degradedBlocked, $now);
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $degradedBlocked
     * @return array<string,mixed>
     */
    private function envelope(string $workspaceId, array $blockers, array $degradedBlocked, int $observedAt, bool $bypassed = false): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'workspace_id' => $workspaceId,
            'ready' => $blockers === [],
            'bypassed_docs_only' => $bypassed,
            'blockers' => array_values($blockers),
            'degraded_but_blocked' => array_values($degradedBlocked),
            'observed_at' => $observedAt,
        ];
    }
}
