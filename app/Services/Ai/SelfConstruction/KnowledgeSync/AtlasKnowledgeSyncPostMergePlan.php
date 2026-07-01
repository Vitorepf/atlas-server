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

    public const ACTION_REFRESH_MEMORY_PROJECTION = 'refresh_memory_projection';

    public const ACTION_RECORD_EVIDENCE_LEDGER = 'record_evidence_ledger';

    public const ACTION_NO_OP = 'no_action';

    public const FILE_CLASS_APP = 'app';

    public const FILE_CLASS_TEST = 'test';

    public const FILE_CLASS_DOCS = 'docs';

    public const FILE_CLASS_CONFIG = 'config';

    public const FILE_CLASS_MIGRATION = 'migration';

    private const DEFAULT_REFRESH_MAX_AGE_SECONDS = 3600;

    /**
     * Substrings identifying critical architecture / queue / task-fabric / proof-system paths.
     * A changed file matching any of these raises stale-brain risk when sync evidence is missing.
     */
    private const CRITICAL_PATH_SUBSTRINGS = [
        'architecture' => ['AutonomousEvolution', 'ArchitectureCouncil'],
        'queue' => ['TaskServing', 'QueueSelfHealing'],
        'task_fabric' => ['TaskGraph', 'TaskFabric'],
        'proof_system' => ['VerificationCourt', 'ProofSystem', 'EvidenceLedger'],
    ];

    /** File-class breadth at/above which a targeted sync is no longer sufficient. */
    private const FULL_SYNC_CLASS_BREADTH_FLOOR = 3;

    /**
     * Explicitly refreshes queue health, queued targets, and code index BEFORE the next
     * origination batch is allowed to rely on them — a next_origination_allowed=true claim can
     * never rest on a refresh that is missing or stale. Pure: only judges supplied refresh facts,
     * never performs the refresh itself.
     *
     * @param  array<string,mixed>  $facts
     *         queue_health?:    {present?:bool, age_seconds?:int}
     *         queued_targets?:  {present?:bool, age_seconds?:int}
     *         code_index?:      {present?:bool, age_seconds?:int}
     *         max_age_seconds?: int  optional override of the freshness ceiling (default 3600)
     * @return array<string,mixed>
     */
    public function planQueueRealityRefresh(array $facts): array
    {
        $maxAgeSeconds = (int) ($facts['max_age_seconds'] ?? self::DEFAULT_REFRESH_MAX_AGE_SECONDS);

        $queueHealthRefresh = $this->refreshStatus($facts['queue_health'] ?? null, $maxAgeSeconds);
        $queuedTargetsRefresh = $this->refreshStatus($facts['queued_targets'] ?? null, $maxAgeSeconds);
        $codeIndexRefresh = $this->refreshStatus($facts['code_index'] ?? null, $maxAgeSeconds);

        $missingRefreshes = [];
        foreach (['queue_health' => $queueHealthRefresh, 'queued_targets' => $queuedTargetsRefresh, 'code_index' => $codeIndexRefresh] as $name => $status) {
            if (! $status['fresh']) {
                $missingRefreshes[] = $name;
            }
        }

        $nextOriginationAllowed = $missingRefreshes === [];

        return [
            'schema_version' => self::SCHEMA,
            'queue_health_refresh' => $queueHealthRefresh,
            'queued_targets_refresh' => $queuedTargetsRefresh,
            'code_index_refresh' => $codeIndexRefresh,
            'missing_refreshes' => $missingRefreshes,
            'next_origination_allowed' => $nextOriginationAllowed,
        ];
    }

    /**
     * @param  mixed  $refresh
     * @return array{present:bool, age_seconds:?int, fresh:bool}
     */
    private function refreshStatus($refresh, int $maxAgeSeconds): array
    {
        $refresh = is_array($refresh) ? $refresh : [];
        $present = (bool) ($refresh['present'] ?? false);
        $ageSeconds = array_key_exists('age_seconds', $refresh) ? (int) $refresh['age_seconds'] : null;
        $fresh = $present && $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds;

        return [
            'present' => $present,
            'age_seconds' => $ageSeconds,
            'fresh' => $fresh,
        ];
    }

    /**
     * Classify changed file paths and produce an ordered sync plan distinguishing mandatory from optional steps.
     *
     * Mandatory: index_code + record_evidence_ledger when implementation (app/migration) files changed.
     * Optional:  sync_docs when only documentation files changed.
     * Always:    refresh_memory_projection + refresh_context_pack when any file changed.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $projectIds
     * @param  array<string,mixed>  $syncEvidence  optional: {code_indexed?:bool, memory_refreshed?:bool, evidence_recorded?:bool}
     * @return array{schema_version:string, actions:list<string>, required_actions:list<string>, optional_actions:list<string>, file_classes:list<string>, has_impl_changes:bool, critical_paths_changed:list<string>, stale_brain_risk:bool, stale_brain_risk_reasons:list<string>, sync_scope:string}
     */
    public function planFromChangedFiles(array $changedFiles, array $projectIds = [], array $syncEvidence = []): array
    {
        $classes = array_values(array_unique($this->classifyFiles($changedFiles)));
        $hasImpl = in_array(self::FILE_CLASS_APP, $classes, true) || in_array(self::FILE_CLASS_MIGRATION, $classes, true);
        $hasTest = in_array(self::FILE_CLASS_TEST, $classes, true);
        $hasDocs = in_array(self::FILE_CLASS_DOCS, $classes, true);
        $any = $changedFiles !== [];

        $required = [];
        $optional = [];
        $actions = [];

        if ($hasImpl) {
            $actions[] = self::ACTION_INDEX_CODE;
            $required[] = self::ACTION_INDEX_CODE;
        }
        if ($hasDocs) {
            $actions[] = self::ACTION_SYNC_DOCS;
            $optional[] = self::ACTION_SYNC_DOCS;
        }
        if ($any) {
            $actions[] = self::ACTION_REFRESH_MEMORY_PROJECTION;
            $required[] = self::ACTION_REFRESH_MEMORY_PROJECTION;
            $actions[] = self::ACTION_REFRESH_CONTEXT_PACK;
            $required[] = self::ACTION_REFRESH_CONTEXT_PACK;
        }
        // Implementation changes always need an evidence ledger record (paired with
        // index_code, in that order). Test-only changes ALSO need evidence recorded —
        // tests are real proof-bearing changes — but must NOT trigger code indexing,
        // which only applies when app/migration source actually changed.
        if ($hasImpl || $hasTest) {
            $actions[] = self::ACTION_RECORD_EVIDENCE_LEDGER;
            $required[] = self::ACTION_RECORD_EVIDENCE_LEDGER;
        }

        if ($actions === []) {
            $actions = [self::ACTION_NO_OP];
        }

        $criticalClasses = $this->criticalPathClasses($changedFiles);

        $codeIndexed = (bool) ($syncEvidence['code_indexed'] ?? false);
        $memoryRefreshed = (bool) ($syncEvidence['memory_refreshed'] ?? false);
        $evidenceRecorded = (bool) ($syncEvidence['evidence_recorded'] ?? false);

        $staleBrainRiskReasons = [];
        foreach ($criticalClasses as $criticalClass) {
            if ($hasImpl && ! $codeIndexed) {
                $staleBrainRiskReasons[] = "{$criticalClass}_changed_without_code_index_evidence";
            }
            if (! $memoryRefreshed) {
                $staleBrainRiskReasons[] = "{$criticalClass}_changed_without_memory_refresh_evidence";
            }
            if (($hasImpl || $hasTest) && ! $evidenceRecorded) {
                $staleBrainRiskReasons[] = "{$criticalClass}_changed_without_evidence_ledger_record";
            }
        }
        $staleBrainRiskReasons = array_values(array_unique($staleBrainRiskReasons));

        // AC3: a targeted refresh is sufficient unless a critical path changed or the breadth
        // of touched file classes is wide enough that partial refresh would leave gaps.
        $syncScope = ($criticalClasses !== [] || count($classes) >= self::FULL_SYNC_CLASS_BREADTH_FLOOR)
            ? 'full'
            : 'targeted';

        return [
            'schema_version' => self::SCHEMA,
            'actions' => $actions,
            'required_actions' => $required,
            'optional_actions' => $optional,
            'file_classes' => $classes,
            'has_impl_changes' => $hasImpl,
            'critical_paths_changed' => $criticalClasses,
            'stale_brain_risk' => $staleBrainRiskReasons !== [],
            'stale_brain_risk_reasons' => $staleBrainRiskReasons,
            'sync_scope' => $syncScope,
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function criticalPathClasses(array $changedFiles): array
    {
        $matched = [];
        foreach ($changedFiles as $path) {
            $path = (string) $path;
            foreach (self::CRITICAL_PATH_SUBSTRINGS as $criticalClass => $substrings) {
                foreach ($substrings as $needle) {
                    if (str_contains($path, $needle)) {
                        $matched[$criticalClass] = true;
                    }
                }
            }
        }

        $classes = array_keys($matched);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function classifyFiles(array $changedFiles): array
    {
        $classes = [];
        foreach ($changedFiles as $path) {
            $path = (string) $path;
            if (str_starts_with($path, 'database/migrations/')) {
                $classes[] = self::FILE_CLASS_MIGRATION;
            } elseif (str_starts_with($path, 'tests/')) {
                $classes[] = self::FILE_CLASS_TEST;
            } elseif (str_starts_with($path, 'docs/') || str_ends_with($path, '.md')) {
                $classes[] = self::FILE_CLASS_DOCS;
            } elseif (str_starts_with($path, 'config/')) {
                $classes[] = self::FILE_CLASS_CONFIG;
            } else {
                $classes[] = self::FILE_CLASS_APP;
            }
        }

        return $classes;
    }

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
                // Implementation changes that require a code index update also require an
                // evidence ledger record — mirrors the mandatory pairing in planFromChangedFiles().
                $actions[] = ['action' => self::ACTION_RECORD_EVIDENCE_LEDGER, 'target' => $codeTargets];
                $commandHints[] = 'atlas evidence-ledger record --source=post_merge';
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
