<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure planner — composes the artifact map + docs drift gate verdict + code-index readiness verdict into
 * an ordered list of post-merge knowledge-maintenance actions, plus matching command_hints for the
 * Merge Governor and Learning Transfer to execute.
 *
 * Output (FACTS only):
 *   {schema_version, blocked, actions, command_hints, blockers, project_ids}
 *
 *   actions: ordered list of {action, project_id?, target?}
 *   command_hints: ordered list of strings (suggestions only — planner NEVER executes)
 *   blocked: true when any required gate is not conformant; planner NEVER marks a release complete
 *
 * Pure: no provider, no command exec, no filesystem write, no DB write.
 */
final class AtlasKnowledgeSyncPostMergePlan
{
    public const SCHEMA = 'atlas.knowledge_sync.post_merge_plan.v1';

    public const ACTION_SYNC_DOCS = 'sync_docs';

    public const ACTION_INDEX_CODE = 'index_code';

    public const ACTION_REFRESH_CONTEXT_PACK = 'refresh_context_pack';

    public const ACTION_RECORD_LEARNING_CANDIDATE = 'record_learning_candidate';

    public const ACTION_NO_OP = 'no_action';

    /**
     * @param  array<string,mixed>  $artifactMap          {project_ids:list<string>, docs_dirs:list<string>, code_index_targets:list<string>}
     * @param  array<string,mixed>  $docsDriftVerdict     produced by an external docs drift gate; must carry {conformant:bool, blockers:list<string>}
     * @param  array<string,mixed>  $codeIndexVerdict     produced by AtlasKnowledgeSyncCodeIndexReadinessGate
     * @param  array<string,mixed>  $candidate            the merged candidate (used to decide whether a learning record is worth recording)
     * @return array<string,mixed>
     */
    public function plan(array $artifactMap, array $docsDriftVerdict, array $codeIndexVerdict, array $candidate = []): array
    {
        $projectIds = array_values((array) ($artifactMap['project_ids'] ?? []));
        $docsConformant = (bool) ($docsDriftVerdict['conformant'] ?? false);
        $codeReady = (bool) ($codeIndexVerdict['ready'] ?? false);
        $codeBypassed = (bool) ($codeIndexVerdict['bypassed_docs_only'] ?? false);

        $blockers = [];
        if (! $docsConformant) {
            $blockers[] = 'docs_drift_not_conformant';
            foreach ((array) ($docsDriftVerdict['blockers'] ?? []) as $b) {
                $blockers[] = 'docs_drift:'.(string) $b;
            }
        }
        if (! $codeReady && ! $codeBypassed) {
            $blockers[] = 'code_index_not_ready';
            foreach ((array) ($codeIndexVerdict['blockers'] ?? []) as $b) {
                $blockers[] = 'code_index:'.(string) $b;
            }
        }

        $blocked = $blockers !== [];

        $actions = [];
        $commandHints = [];

        if (! $blocked) {
            // Docs sync, code index, context pack refresh per project, then a learning candidate when the
            // candidate carries evidence worth recording.
            $docsDirs = array_values((array) ($artifactMap['docs_dirs'] ?? []));
            if ($docsDirs !== []) {
                $actions[] = ['action' => self::ACTION_SYNC_DOCS, 'target' => $docsDirs];
                $commandHints[] = 'atlas engineering knowledge sync --prune';
            }
            $codeTargets = array_values((array) ($artifactMap['code_index_targets'] ?? []));
            if ($codeTargets !== [] && ! $codeBypassed) {
                $actions[] = ['action' => self::ACTION_INDEX_CODE, 'target' => $codeTargets];
                $commandHints[] = 'atlas engineering knowledge index-code --prune';
            }
            foreach ($projectIds as $pid) {
                $actions[] = ['action' => self::ACTION_REFRESH_CONTEXT_PACK, 'project_id' => $pid];
                $commandHints[] = 'atlas open-brain context --workspace='.$pid.' --refresh';
            }
            if ($this->candidateWorthRecording($candidate)) {
                $actions[] = ['action' => self::ACTION_RECORD_LEARNING_CANDIDATE, 'target' => $candidate['candidate_id'] ?? null];
                $commandHints[] = 'atlas memory record --kind=learning --source=post_merge';
            }
            if ($actions === []) {
                $actions[] = ['action' => self::ACTION_NO_OP];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'blocked' => $blocked,
            'actions' => $actions,
            'command_hints' => $commandHints,
            'blockers' => $blockers,
            'project_ids' => $projectIds,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function candidateWorthRecording(array $candidate): bool
    {
        if (($candidate['certified'] ?? null) !== true) {
            return false;
        }
        if (($candidate['evidence_refs'] ?? null) === [] || ! is_array($candidate['evidence_refs'] ?? null)) {
            return false;
        }

        return true;
    }
}
