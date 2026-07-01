<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure gate. Blocks Self-Construction completion when REQUIRED documentation evidence is stale, missing
 * or contradictory. Composes:
 *   - required_artifacts (from {@see AtlasKnowledgeSyncRequiredArtifactMap})
 *   - observed docs_health    {ok:bool, observed_at_unix:int, debt_facts?:list<string>}
 *   - observed sync_result    {ok:bool, observed_at_unix:int}
 *   - observed code_index     {ok:bool, observed_at_unix:int}
 *   - observed memory_update  {ok:bool, observed_at_unix:int}
 *   - changed_docs (raw doc paths that changed)
 *   - capability_changed (bool: completed task changed capability, prompt contract, task fabric
 *     behavior, or public architecture)
 *
 * INVARIANTS:
 *   - conformant=false with NAMED blockers when artifact required & evidence missing / stale / failing.
 *   - PASSES when no doc artifact is required (no canonical docs changed) — bypass.
 *   - capability_changed fails closed unless docs-health-check, engineering-knowledge-sync,
 *     code-intelligence-index AND memory-update are ALL required and their evidence is fresh —
 *     a capability-changing task must never let the next batch reason from stale context.
 *   - debt_facts surfaced verbatim — NEVER aggregated into a scalar score.
 *   - DETERMINISTIC envelope (blockers sorted).
 */
final class AtlasKnowledgeSyncDocsDriftGate
{
    public const SCHEMA = 'atlas.knowledgesync.docs_drift_gate.v1';

    public const STALE_WINDOW_SECONDS_DEFAULT = 86400;

    /**
     * @param  array{
     *     required_artifacts:list<array{artifact_id?:string}>,
     *     docs_health?:array{ok?:bool, observed_at_unix?:int, debt_facts?:list<string>},
     *     sync_result?:array{ok?:bool, observed_at_unix?:int},
     *     code_index?:array{ok?:bool, observed_at_unix?:int},
     *     memory_update?:array{ok?:bool, observed_at_unix?:int},
     *     changed_docs?:list<string>,
     *     capability_changed?:bool,
     *     now_unix?:int,
     *     stale_window_seconds?:int
     * }  $facts
     * @return array{schema:string, conformant:bool, blockers:list<string>, debt_facts:list<string>, required_artifacts:list<string>, next_sync_actions:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $now = (int) ($facts['now_unix'] ?? 0);
        $window = (int) ($facts['stale_window_seconds'] ?? self::STALE_WINDOW_SECONDS_DEFAULT);
        $artifactIds = array_values(array_map(static fn (array $a): string => (string) ($a['artifact_id'] ?? ''), (array) ($facts['required_artifacts'] ?? [])));
        $needsDocsHealth = in_array('docs-health-check', $artifactIds, true);
        $needsSync = in_array('engineering-knowledge-sync', $artifactIds, true);
        $needsCodeIndex = in_array('code-intelligence-index', $artifactIds, true);
        $needsMemoryUpdate = in_array('memory-update', $artifactIds, true);
        $capabilityChanged = (bool) ($facts['capability_changed'] ?? false);

        $blockers = [];
        $debt = [];

        // Fail closed: a capability/prompt-contract/task-fabric/public-architecture change must
        // require ALL four sync artifacts, or the next batch reasons from stale context.
        if ($capabilityChanged) {
            if (! $needsDocsHealth) {
                $blockers[] = 'capability_changed_but_docs_health_check_not_required';
            }
            if (! $needsSync) {
                $blockers[] = 'capability_changed_but_knowledge_sync_not_required';
            }
            if (! $needsCodeIndex) {
                $blockers[] = 'capability_changed_but_code_index_not_required';
            }
            if (! $needsMemoryUpdate) {
                $blockers[] = 'capability_changed_but_memory_update_not_required';
            }
        }

        // Fail closed: canonical docs changed but required artifacts don't ask for docs evidence.
        $hasDocChanges = array_filter(
            (array) ($facts['changed_docs'] ?? []),
            static fn (mixed $p): bool => is_string($p) && self::isCanonicalDocPath($p)
        ) !== [];
        if ($hasDocChanges && ! $needsDocsHealth) {
            $blockers[] = 'docs_changed_but_docs_health_check_not_required';
        }
        if ($hasDocChanges && ! $needsSync) {
            $blockers[] = 'docs_changed_but_knowledge_sync_not_required';
        }

        if ($needsDocsHealth) {
            $dh = is_array($facts['docs_health'] ?? null) ? $facts['docs_health'] : null;
            if ($dh === null) {
                $blockers[] = 'docs_health_missing';
            } else {
                if (! ($dh['ok'] ?? false)) {
                    $blockers[] = 'docs_health_not_ok';
                }
                if (! isset($dh['observed_at_unix'])) {
                    $blockers[] = 'docs_health_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $dh['observed_at_unix']) > $window) {
                    $blockers[] = 'docs_health_stale';
                }
                foreach ((array) ($dh['debt_facts'] ?? []) as $d) {
                    $debt[] = 'docs_health:'.(string) $d;
                }
            }
        }

        if ($needsSync) {
            $sr = is_array($facts['sync_result'] ?? null) ? $facts['sync_result'] : null;
            if ($sr === null) {
                $blockers[] = 'sync_result_missing';
            } else {
                if (! ($sr['ok'] ?? false)) {
                    $blockers[] = 'sync_result_not_ok';
                }
                if (! isset($sr['observed_at_unix'])) {
                    $blockers[] = 'sync_result_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $sr['observed_at_unix']) > $window) {
                    $blockers[] = 'sync_result_stale';
                }
            }
        }

        // Code-index / memory-update evidence only enforced for capability-changing tasks — a plain
        // doc edit does not need a fresh code graph or memory write, but a capability change does.
        if ($capabilityChanged && $needsCodeIndex) {
            $ci = is_array($facts['code_index'] ?? null) ? $facts['code_index'] : null;
            if ($ci === null) {
                $blockers[] = 'code_index_missing';
            } else {
                if (! ($ci['ok'] ?? false)) {
                    $blockers[] = 'code_index_not_ok';
                }
                if (! isset($ci['observed_at_unix'])) {
                    $blockers[] = 'code_index_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $ci['observed_at_unix']) > $window) {
                    $blockers[] = 'code_index_stale';
                }
            }
        }

        if ($capabilityChanged && $needsMemoryUpdate) {
            $mu = is_array($facts['memory_update'] ?? null) ? $facts['memory_update'] : null;
            if ($mu === null) {
                $blockers[] = 'memory_update_missing';
            } else {
                if (! ($mu['ok'] ?? false)) {
                    $blockers[] = 'memory_update_not_ok';
                }
                if (! isset($mu['observed_at_unix'])) {
                    $blockers[] = 'memory_update_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $mu['observed_at_unix']) > $window) {
                    $blockers[] = 'memory_update_stale';
                }
            }
        }

        sort($blockers, SORT_STRING);
        sort($debt, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'conformant' => $blockers === [],
            'blockers' => $blockers,
            'debt_facts' => $debt,
            'required_artifacts' => $artifactIds,
            'next_sync_actions' => $this->nextSyncActions($blockers),
        ];
    }

    private static function isCanonicalDocPath(string $path): bool
    {
        return str_contains($path, 'docs/') || str_contains($path, 'droid-wiki/');
    }

    /**
     * Maps blockers to concrete remediation actions the next batch (or the operator) can run —
     * a bare conformant=false leaves the caller guessing which of 4 sync surfaces to fix.
     *
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextSyncActions(array $blockers): array
    {
        $actions = [];
        foreach ($blockers as $blocker) {
            $actions[] = match (true) {
                str_starts_with($blocker, 'docs_health') => 'run_docs_health_check',
                str_starts_with($blocker, 'sync_result') => 'run_engineering_knowledge_sync',
                str_starts_with($blocker, 'code_index') => 'run_index_code',
                str_starts_with($blocker, 'memory_update') => 'record_memory_update',
                str_starts_with($blocker, 'docs_changed_but_') || str_starts_with($blocker, 'capability_changed_but_') => 'add_missing_required_artifact',
                default => 'investigate_drift_blocker',
            };
        }

        $actions = array_values(array_unique($actions));
        sort($actions, SORT_STRING);

        return $actions;
    }
}
