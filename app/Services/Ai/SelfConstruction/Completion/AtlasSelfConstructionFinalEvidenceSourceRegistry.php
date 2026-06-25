<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Deterministic registry of the mandatory evidence sources that final Atlas-native completion must
 * inspect. NO scalar scoring — every required source is blocking for final ready state; refreshable
 * sources are merely labelled separately from unsafe blockers (they can be re-derived locally).
 *
 * Output keys (always present, sorted byte-stably):
 *   - schema_version
 *   - required_sources    list<{id, label, group, blocking, refreshable, evidence_kinds}>
 *   - source_groups       map<group, list<source_id>>
 *   - blocking_source_ids list<source_id>  (= every required source id; explicit by acceptance)
 *   - proof_summary       { total_sources, blocking_count, refreshable_count, unsafe_blocker_count }
 */
final class AtlasSelfConstructionFinalEvidenceSourceRegistry
{
    public const SCHEMA = 'atlas.self_construction.final_evidence_source_registry.v1';

    public const GROUP_TASK_SERVING = 'task_serving';
    public const GROUP_KNOWLEDGE = 'knowledge';
    public const GROUP_GOVERNANCE = 'governance';
    public const GROUP_NATIVE_RUNTIME = 'native_runtime';
    public const GROUP_VERIFICATION = 'verification';
    public const GROUP_RECOVERY = 'recovery';
    public const GROUP_LEARNING = 'learning';
    public const GROUP_DOCS = 'docs';
    public const GROUP_TASK_FABRIC_FINAL_COVERAGE = 'task_fabric_final_coverage';

    public const GROUP_UNATTENDED_RUNTIME = 'unattended_runtime';

    /**
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $sources = $this->sources();
        // Deterministic stable order by source id.
        usort($sources, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        $groupIndex = [];
        $blockingIds = [];
        $refreshableCount = 0;
        $unsafeBlockerCount = 0;
        foreach ($sources as $s) {
            $group = (string) $s['group'];
            $groupIndex[$group] ??= [];
            $groupIndex[$group][] = (string) $s['id'];
            if ((bool) $s['blocking']) {
                $blockingIds[] = (string) $s['id'];
                if ((bool) $s['refreshable']) {
                    $refreshableCount++;
                } else {
                    $unsafeBlockerCount++;
                }
            }
        }
        foreach ($groupIndex as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);
        ksort($groupIndex);
        sort($blockingIds, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'required_sources' => $sources,
            'source_groups' => $groupIndex,
            'blocking_source_ids' => $blockingIds,
            'proof_summary' => [
                'total_sources' => count($sources),
                'blocking_count' => count($blockingIds),
                'refreshable_count' => $refreshableCount,
                'unsafe_blocker_count' => $unsafeBlockerCount,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sources(): array
    {
        return [
            [
                'id' => 'task_serving_contract_sentinel',
                'label' => 'Task Simplicity Contract Sentinel',
                'group' => self::GROUP_TASK_SERVING,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['contract_sentinel_verdict'],
            ],
            [
                'id' => 'code_index_readiness_bridge',
                'label' => 'Code Intelligence Readiness Bridge',
                'group' => self::GROUP_KNOWLEDGE,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['code_index_readiness'],
            ],
            [
                'id' => 'multi_project_governance_dossier',
                'label' => 'Multi-Project Governance Dossier',
                'group' => self::GROUP_GOVERNANCE,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['governance_dossier'],
            ],
            [
                'id' => 'native_worker_readiness',
                'label' => 'Native Worker Readiness',
                'group' => self::GROUP_NATIVE_RUNTIME,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['native_worker_handshake'],
            ],
            [
                'id' => 'verification_court',
                'label' => 'Verification Court Verdict',
                'group' => self::GROUP_VERIFICATION,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['verification_court_verdict'],
            ],
            [
                'id' => 'merge_governor',
                'label' => 'Merge Governor Verdict',
                'group' => self::GROUP_GOVERNANCE,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['merge_governor_verdict'],
            ],
            [
                'id' => 'rollback',
                'label' => 'Rollback Proof',
                'group' => self::GROUP_RECOVERY,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['rollback_proof'],
            ],
            [
                'id' => 'receipts',
                'label' => 'Append-only Receipts',
                'group' => self::GROUP_VERIFICATION,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['evidence_receipt'],
            ],
            [
                'id' => 'learning_transfer',
                'label' => 'Learning Transfer Evidence',
                'group' => self::GROUP_LEARNING,
                'blocking' => true,
                'refreshable' => false,
                'evidence_kinds' => ['learning_transfer'],
            ],
            [
                'id' => 'docs_health',
                'label' => 'Documentation Health',
                'group' => self::GROUP_DOCS,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['docs_health_report'],
            ],
            [
                'id' => 'knowledge_sync',
                'label' => 'Knowledge & Code Index Sync',
                'group' => self::GROUP_KNOWLEDGE,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['knowledge_sync_report'],
            ],
            [
                'id' => 'task_graph_coverage_dossier',
                'label' => 'Task Graph Coverage Dossier',
                'group' => self::GROUP_TASK_FABRIC_FINAL_COVERAGE,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['task_graph_coverage_dossier'],
            ],
            [
                'id' => 'task_graph_autonomous_replenisher',
                'label' => 'Task Graph Autonomous Replenisher Receipt',
                'group' => self::GROUP_TASK_FABRIC_FINAL_COVERAGE,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['task_graph_autonomous_replenisher_receipt'],
                'required_fields' => [
                    'plan_hash',
                    'dry_run',
                    'applied_count',
                    'withheld_count',
                    'duplicate_count',
                    'replenisher_hash',
                ],
            ],
            [
                'id' => 'scope_expansion_governor',
                'label' => 'Scope Expansion Governor Cycle Receipt',
                'group' => self::GROUP_TASK_FABRIC_FINAL_COVERAGE,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['scope_expansion_governor_cycle_receipt'],
                'schema_version' => 'atlas.self_construction.scope_expansion_governor_cycle.v1',
                'required_fields' => [
                    'admitted_count',
                    'withheld_count',
                    'applied_actions',
                    'blocked_actions',
                    'withheld_actions',
                    'governor_cycle_hash',
                ],
                'requires_no_dependency_on' => ['operator', 'human', 'external_provider'],
                'links_to_when_external_project_lane' => 'multi_project_governance_dossier',
            ],
            [
                'id' => 'native_worker_runtime',
                'label' => 'Native Worker Pool Supervisor Runtime Receipt',
                'group' => self::GROUP_NATIVE_RUNTIME,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['native_worker_pool_supervisor_receipt'],
                'schema_version' => 'atlas.native_worker.pool_supervisor.v1',
                'required_fields' => [
                    'cycle_count',
                    'success_count',
                    'give_back_count',
                    'failed_count',
                    'blocked_count',
                    'safety_stop',
                    'receipts',
                    'supervisor_hash',
                ],
                'required_runtime_proof' => [
                    'claim',
                    'envelope',
                    'materialization',
                    'command_gates',
                    'evidence_write',
                    'report_outcome_mapping',
                    'one_dry_run_cycle',
                    'one_apply_mode_cycle',
                ],
                'requires_no_dependency_on' => [
                    'operator',
                    'human',
                    'claude_code',
                    'codex',
                    'cursor',
                    'external_provider',
                ],
            ],
            [
                'id' => 'unattended_runtime_supervisor',
                'label' => 'Unattended Runtime Supervisor Tick',
                'group' => self::GROUP_UNATTENDED_RUNTIME,
                'blocking' => true,
                'refreshable' => true,
                'evidence_kinds' => ['unattended_runtime_supervisor_receipt'],
                'required_fields' => [
                    'snapshot_hash',
                    'classifier_hash',
                    'plan_hash',
                    'supervisor_cycle_hash',
                    'dry_run',
                    'applied_actions',
                    'blocked_actions',
                ],
                'unsafe_classifications' => [
                    'unsafe_stop',
                    'heartbeat_stale',
                    'merge_blocked',
                    'verification_blocked',
                    'worker_unavailable',
                ],
                'safe_classifications' => [
                    'healthy',
                    'queue_dry',
                    'waiting_on_dependencies',
                    'replenisher_blocked',
                ],
            ],
        ];
    }
}
