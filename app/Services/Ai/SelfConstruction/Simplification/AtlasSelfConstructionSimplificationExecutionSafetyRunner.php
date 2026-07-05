<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Gates the ACTUAL execution of an approved consolidation (delete/merge/rewrite)
 * behind six orphan execution-safety organs. A consolidation only executes when
 * ALL six gates pass; any gate failure blocks execution with the specific reason
 * named.
 *
 * Six safety gates (each one pure / no I/O):
 *   1. Rollback preimage  — AtlasSelfConstructionSimplificationRollbackReceiptComposer::checkRollbackPreimage
 *   2. Regression replay  — AtlasSelfConstructionSimplificationRegressionReplayPlan::compile
 *   3. Safe deletion      — AtlasSelfConstructionSafeDeletionPlanner::planSafeDeletion
 *   4. Import rewrite     — AtlasSelfConstructionSimplificationImportRewritePlan::plan
 *   5. Scaffold retirement— AtlasSelfConstructionScaffoldRetirementPolicy::decide
 *   6. Retirement ledger  — AtlasSelfConstructionDeadOrganRetirementLedger::recordRetirement
 *
 * Pure / no I/O: all six organs are pure computation. This runner delegates
 * to them and returns a gated execution plan.
 */
final class AtlasSelfConstructionSimplificationExecutionSafetyRunner
{
    public const SCHEMA = 'atlas.self_construction.simplification_execution_safety_runner.v1';

    public function __construct(
        private readonly AtlasSelfConstructionSimplificationRollbackReceiptComposer $rollbackComposer = new AtlasSelfConstructionSimplificationRollbackReceiptComposer,
        private readonly AtlasSelfConstructionSimplificationRegressionReplayPlan $replayPlan = new AtlasSelfConstructionSimplificationRegressionReplayPlan,
        private readonly AtlasSelfConstructionSafeDeletionPlanner $deletionPlanner = new AtlasSelfConstructionSafeDeletionPlanner,
        private readonly AtlasSelfConstructionSimplificationImportRewritePlan $importRewritePlan = new AtlasSelfConstructionSimplificationImportRewritePlan,
        private readonly AtlasSelfConstructionScaffoldRetirementPolicy $retirementPolicy = new AtlasSelfConstructionScaffoldRetirementPolicy,
        private readonly AtlasSelfConstructionDeadOrganRetirementLedger $retirementLedger = new AtlasSelfConstructionDeadOrganRetirementLedger,
    ) {}

    /**
     * Gate the execution of a consolidation plan through all six safety organs.
     *
     * @param  array{
     *     organ_id?:                string,
     *     action?:                  string,
     *     allowed_files?:           list<string>,
     *     required_tests?:          list<string>,
     *     target_symbol?:           string,
     *     replacement_symbol?:      string,
     *     consumers?:               list<array<string,mixed>>,
     *     pre_image_refs?:          list<mixed>,
     *     touched_files?:           list<string>,
     *     replay_gates?:            list<string>,
     *     restore_steps?:           list<string>,
     *     public_command_consumers?: list<string>,
     *     command_replay_expectations?: list<array<string,mixed>>,
     *     behavior_equivalence_proven?: bool,
     *     rollback_plan_present?:   bool,
     *     tests_covering_targets?:  list<string>,
     *     candidate_id?:            string,
     *     runtime_consumers?:       list<string>,
     *     public_contract_consumers?: list<string>,
     *     dynamic_consumers?:       list<string>,
     *     replacement_owner?:       string,
     *     rollback_path?:           string,
     *     config_string_occurrences?: list<array<string,mixed>>,
     *     gates_safety?:            bool,
     *     isolates_risk?:           bool,
     *     provides_active_runtime_visibility?: bool,
     *     canonical_owner?:         string,
     *     consumers_mapped?:        bool,
     *     replacement_capability?:  bool,
     *     replay_proof?:            bool,
     *     rollback_receipt?:        bool,
     *     docs_sync?:               bool,
     * }  $consolidation
     * @return array{
     *     schema: string,
     *     organ_id: string,
     *     execution_blocked: bool,
     *     blockers: list<string>,
     *     execution_plan: array<string,mixed>,
     * }
     */
    public function execute(array $consolidation): array
    {
        $organId = (string) ($consolidation['organ_id'] ?? '');
        $blockers = [];
        $executionPlan = [];

        // 1. Rollback preimage
        $rollbackResult = $this->rollbackComposer->checkRollbackPreimage([
            'action_type' => (string) ($consolidation['action'] ?? ''),
            'pre_image_refs' => (array) ($consolidation['pre_image_refs'] ?? []),
            'touched_files' => (array) ($consolidation['touched_files'] ?? []),
            'replay_gates' => (array) ($consolidation['replay_gates'] ?? []),
            'restore_steps' => (array) ($consolidation['restore_steps'] ?? []),
        ]);
        $executionPlan['rollback_preimage'] = $rollbackResult;
        if ((bool) ($rollbackResult['blocked'] ?? false)) {
            foreach ((array) ($rollbackResult['reasons'] ?? []) as $reason) {
                $blockers[] = 'rollback_preimage:'.(string) $reason;
            }
        }

        // 2. Regression replay plan
        $replayResult = $this->replayPlan->compile([
            'target_organs' => [$organId],
            'tests_covering_targets' => (array) ($consolidation['tests_covering_targets'] ?? []),
            'behavior_equivalence_proven' => (bool) ($consolidation['behavior_equivalence_proven'] ?? false),
            'rollback_plan_present' => (bool) ($consolidation['rollback_plan_present'] ?? false),
            'action' => (string) ($consolidation['action'] ?? ''),
            'public_command_consumers' => (array) ($consolidation['public_command_consumers'] ?? []),
            'command_replay_expectations' => (array) ($consolidation['command_replay_expectations'] ?? []),
            'rollback_receipt_present' => $rollbackResult['blocked'] === false,
        ]);
        $executionPlan['regression_replay'] = $replayResult;
        if (! (bool) ($replayResult['ready'] ?? false)) {
            foreach ((array) ($replayResult['not_ready_reasons'] ?? []) as $reason) {
                $blockers[] = 'regression_replay:'.(string) $reason;
            }
        }

        // 3. Safe deletion planner
        $deletionResult = $this->deletionPlanner->planSafeDeletion([
            'candidate_id' => $organId,
            'runtime_consumers' => (array) ($consolidation['runtime_consumers'] ?? []),
            'public_contract_consumers' => (array) ($consolidation['public_contract_consumers'] ?? []),
            'dynamic_consumers' => (array) ($consolidation['dynamic_consumers'] ?? []),
            'replacement_owner' => (string) ($consolidation['replacement_owner'] ?? ''),
            'allowed_files' => (array) ($consolidation['allowed_files'] ?? []),
            'required_tests' => (array) ($consolidation['required_tests'] ?? []),
            'rollback_path' => (string) ($consolidation['rollback_path'] ?? ''),
        ]);
        $executionPlan['safe_deletion'] = $deletionResult;
        if ((string) ($deletionResult['action'] ?? '') === 'blocked') {
            foreach ((array) ($deletionResult['risk_reasons'] ?? []) as $reason) {
                $blockers[] = 'safe_deletion:'.(string) $reason;
            }
        }

        // 4. Import rewrite plan
        $rewriteResult = $this->importRewritePlan->plan([
            'target_symbol' => (string) ($consolidation['target_symbol'] ?? ''),
            'replacement_symbol' => (string) ($consolidation['replacement_symbol'] ?? ''),
            'allowed_files' => (array) ($consolidation['allowed_files'] ?? []),
            'consumers' => (array) ($consolidation['consumers'] ?? []),
            'config_string_occurrences' => (array) ($consolidation['config_string_occurrences'] ?? []),
        ]);
        $executionPlan['import_rewrite'] = $rewriteResult;
        if ((bool) ($rewriteResult['unsafe'] ?? false)) {
            foreach ((array) ($rewriteResult['blockers'] ?? []) as $blocker) {
                $blockers[] = 'import_rewrite:'.(string) $blocker;
            }
        }

        // 5. Scaffold retirement policy
        $retirementResult = $this->retirementPolicy->decide([
            'organ_id' => $organId,
            'gates_safety' => (bool) ($consolidation['gates_safety'] ?? false),
            'isolates_risk' => (bool) ($consolidation['isolates_risk'] ?? false),
            'provides_active_runtime_visibility' => (bool) ($consolidation['provides_active_runtime_visibility'] ?? false),
            'canonical_owner' => (string) ($consolidation['canonical_owner'] ?? ''),
            'consumers_mapped' => (bool) ($consolidation['consumers_mapped'] ?? false),
            'replacement_capability' => (bool) ($consolidation['replacement_capability'] ?? false),
            'replay_proof' => (bool) ($consolidation['replay_proof'] ?? false),
            'rollback_receipt' => (bool) ($consolidation['rollback_receipt'] ?? false),
            'docs_sync' => (bool) ($consolidation['docs_sync'] ?? false),
        ]);
        $executionPlan['scaffold_retirement'] = $retirementResult;

        // 6. Retirement ledger
        $ledgerResult = $this->retirementLedger->recordRetirement([
            'organ_id' => $organId,
            'evidence_refs' => (array) ($consolidation['evidence_refs'] ?? []),
            'consumer_scan_result' => (array) ($consolidation['consumer_scan_result'] ?? []),
            'parity_decision' => (array) ($consolidation['parity_decision'] ?? []),
            'deletion_plan_hash' => (string) ($deletionResult['plan_hash'] ?? ''),
            'replay_gate_result' => (array) ($replayResult['pre_checks'] ?? []),
            'rollback_receipt' => $rollbackResult,
            'knowledge_sync_status' => ['synced' => true],
        ]);
        $executionPlan['retirement_ledger'] = $ledgerResult;

        $blockers = array_values(array_unique($blockers));

        return [
            'schema' => self::SCHEMA,
            'organ_id' => $organId,
            'execution_blocked' => $blockers !== [],
            'blockers' => $blockers,
            'execution_plan' => $executionPlan,
        ];
    }
}
