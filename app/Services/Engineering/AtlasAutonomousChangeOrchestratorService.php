<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;

final class AtlasAutonomousChangeOrchestratorService
{
    public const SCHEMA_VERSION = 'atlas.autonomous_change_orchestrator.v1';

    public function __construct(
        private readonly AtlasVerifiedEvolutionRuntimeService $verifiedEvolution,
        private readonly AtlasVerifiedExecutionRuntimeService $verifiedExecution,
        private readonly AtlasAutonomousProductDeliveryRuntimeService $productDelivery,
    ) {}

    /**
     * Compose the autonomous change plan from the existing canonical runtimes:
     * Software Twin impact -> Verified Evolution contract -> AVER plan -> repair
     * policy. The orchestrator is contract-first and does not authorize mutation.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(string $objective, string $target, array $options = []): array
    {
        $objective = trim($objective);
        $target = trim($target);
        $changedFiles = $this->stringList($options['changed_files'] ?? []);
        $includeContracts = (bool) ($options['include_contracts'] ?? false);

        $executionContract = $this->verifiedEvolution->executionContract($objective, $target);
        $proofPlan = $this->verifiedEvolution->proofPlan($objective, $target);
        $patchSimulation = $this->verifiedEvolution->patchSimulation($objective, $target, $changedFiles);
        $productDelivery = $this->productDelivery->plan([
            'human_request' => $objective,
            'workspace' => (string) ($options['workspace'] ?? 'atlas-server'),
            'target' => (string) ($options['product_route'] ?? 'atlas_dev'),
            'operator_approved' => (bool) ($options['operator_approved'] ?? true),
            'context_refs' => $this->stringList($options['context_refs'] ?? []),
            'evidence_refs' => $this->stringList($options['evidence_refs'] ?? []),
            'business_context' => is_array($options['business_context'] ?? null) ? $options['business_context'] : [],
        ]);
        $averPlan = $this->verifiedExecution->planFromVerifiedEvolutionContract($executionContract, [
            'workspace' => (string) ($options['workspace'] ?? base_path()),
            'aweos_execution_id' => $options['aweos_execution_id'] ?? null,
        ]);

        $causalVerification = (array) data_get($proofPlan, 'proof_plan.causal_verification', []);
        $causalPaths = (array) ($causalVerification['causal_paths'] ?? []);
        $readFirst = $this->stringList($causalVerification['read_first'] ?? data_get($executionContract, 'execution_contract.aver_plan_input.read_first', []));
        $requiredTests = $this->stringList(data_get($proofPlan, 'proof_plan.required_tests', []));
        $requiredGates = $this->stringList(data_get($proofPlan, 'proof_plan.required_gates', []));
        $allowedWritePaths = $this->stringList(data_get($executionContract, 'execution_contract.aver_plan_input.allowed_write_paths', []));

        $blockers = $this->blockersFrom([
            'verified_evolution_execution_contract' => $executionContract,
            'verified_evolution_proof_plan' => $proofPlan,
            'verified_evolution_patch_simulation' => $patchSimulation,
            'aver_plan' => $averPlan,
        ]);

        if ($objective === '') {
            $blockers[] = 'objective_required';
        }
        if ($target === '') {
            $blockers[] = 'target_required';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'writes' => (bool) ($averPlan['writes'] ?? false),
            'objective_hash' => $objective === '' ? null : MissionCanonicalHash::sha256($objective),
            'target' => $target,
            'orchestrator' => [
                'mode' => 'contract_first_autonomous_change_orchestration',
                'context_delivery' => 'minimal_by_default_expand_on_demand',
                'provider_invoked_directly' => false,
                'mutation_authorized' => false,
                'ledger_writes' => (bool) ($averPlan['writes'] ?? false),
                'requires_aver_for_execution' => true,
                'requires_human_review_before_live_write' => true,
            ],
            'context_start' => [
                'schema_version' => 'atlas.autonomous_change_orchestrator.context_start.v1',
                'policy' => 'read_minimal_target_and_owner_context_before_expanding_tests_or_docs',
                'read_first' => array_slice($readFirst, 0, 5),
                'counts' => [
                    'read_first_total' => count($readFirst),
                    'required_tests_total' => count($requiredTests),
                    'required_gates_total' => count($requiredGates),
                    'causal_paths_total' => count($causalPaths),
                ],
                'expand_when_needed' => [
                    'tests' => [
                        'when' => 'verification_planning_failed_run_repair_or_contract_change',
                        'command' => 'php artisan atlas:verified-evolution proof-plan --target="'.$target.'" --objective="<objective>" --json',
                        'count' => count($requiredTests),
                    ],
                    'docs' => [
                        'when' => 'ownership_policy_architecture_or_domain_boundary_is_unclear',
                        'command' => 'php artisan atlas:software-twin impact --target="'.$target.'" --json',
                        'count' => count($readFirst),
                    ],
                    'contracts' => [
                        'when' => 'provider_or_subagent_needs_full_APDR_AVEOR_AVER_contracts',
                        'command' => 'php artisan atlas:autonomous-change-orchestrator plan --target="'.$target.'" --objective="<objective>" --include-contracts --json',
                        'included_by_default' => $includeContracts,
                    ],
                ],
            ],
            'multi_step_plan' => $this->multiStepPlan($allowedWritePaths, $readFirst, $requiredTests, $requiredGates, $causalPaths),
            'multi_agent_schedule' => $this->multiAgentSchedule($allowedWritePaths, $readFirst, $requiredTests, $requiredGates, $causalPaths),
            'causal_verification' => [
                'schema_version' => 'atlas.autonomous_change_orchestrator.causal_verification.v1',
                'graph_status' => (string) ($causalVerification['graph_status'] ?? 'degraded'),
                'confidence' => $causalVerification['confidence'] ?? ['score' => 0, 'label' => 'none'],
                'causal_path_count' => count($causalPaths),
                'causal_paths' => array_slice($causalPaths, 0, 12),
                'read_first' => array_slice($readFirst, 0, 5),
                'expansion_available' => [
                    'causal_paths' => count($causalPaths),
                    'read_first' => count($readFirst),
                    'required_tests' => count($requiredTests),
                ],
            ],
            'product_business_twin' => [
                'schema_version' => 'atlas.autonomous_change_orchestrator.product_business_twin.v1',
                'delivery_status' => (string) ($productDelivery['status'] ?? 'unknown'),
                'product_twin_status' => (string) data_get($productDelivery, 'product_twin_simulation.status', 'unknown'),
                'business_twin' => (array) data_get($productDelivery, 'product_twin_simulation.business_twin', []),
                'risk_governor' => [
                    'schema_version' => data_get($productDelivery, 'risk_governor.schema_version'),
                    'status' => data_get($productDelivery, 'risk_governor.status'),
                    'blockers' => (array) data_get($productDelivery, 'risk_governor.blockers', []),
                ],
                'delivery_route' => (string) ($productDelivery['route'] ?? 'unknown'),
            ],
            'repair_strategy' => [
                'schema_version' => 'atlas.autonomous_change_orchestrator.repair_strategy.v1',
                'trigger' => 'any_failed_command_diff_or_test_ledger',
                'runtime' => 'AtlasVerifiedExecutionRuntimeService::repair',
                'max_attempts' => max(1, min(5, (int) ($options['max_repair_attempts'] ?? 3))),
                'retrieval_context' => array_slice($readFirst, 0, 12),
                'completion_requires' => [
                    'repair_cycle_recorded_when_failure_occurs',
                    'rerun_failed_tests_after_repair',
                    'certification_blocked_if_repair_not_proven',
                ],
            ],
            'contracts' => $includeContracts
                ? [
                    'detail_level' => 'full',
                    'verified_evolution_execution_contract' => $executionContract,
                    'verified_evolution_proof_plan' => $proofPlan,
                    'verified_evolution_patch_simulation' => $patchSimulation,
                    'product_delivery_plan' => $productDelivery,
                    'aver_plan' => $averPlan,
                ]
                : $this->contractSummaries($executionContract, $proofPlan, $patchSimulation, $productDelivery, $averPlan),
            'blockers' => EngineeringStringListNormalizer::uniqueNonEmptyStrings($blockers),
            'claim_policy' => [
                'code_read_only' => true,
                'ledger_writes' => (bool) ($averPlan['writes'] ?? false),
                'providers_invoked' => false,
                'executes_commands' => false,
                'authorizes_mutation' => false,
                'full_autonomous_change_complete_claimed' => false,
            ],
        ];
        $payload['orchestration_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $allowedWritePaths
     * @param  array<int,string>  $readFirst
     * @param  array<int,string>  $requiredTests
     * @param  array<int,string>  $requiredGates
     * @param  array<int,array<string,mixed>>  $causalPaths
     * @return array<int,array<string,mixed>>
     */
    private function multiStepPlan(array $allowedWritePaths, array $readFirst, array $requiredTests, array $requiredGates, array $causalPaths): array
    {
        return [
            [
                'step_id' => 'S1',
                'name' => 'causal_context_lock',
                'owner_role' => 'architect',
                'inputs' => array_slice($readFirst, 0, 5),
                'deferred_context' => [
                    'read_first_total' => count($readFirst),
                    'load_more_only_if_boundary_unclear' => true,
                ],
                'outputs' => ['causal_paths_reviewed', 'boundary_confirmed'],
                'completion_gate' => 'proof_plan.causal_verification.graph_status_ready_or_degraded_declared',
            ],
            [
                'step_id' => 'S2',
                'name' => 'scoped_patch_plan',
                'owner_role' => 'implementer',
                'inputs' => $allowedWritePaths,
                'outputs' => ['action_manifest', 'changed_files_within_boundary'],
                'completion_gate' => 'scope_drift_watch_passed',
            ],
            [
                'step_id' => 'S3',
                'name' => 'verified_execution',
                'owner_role' => 'verifier',
                'inputs' => [
                    'verification_plan_available',
                    'required_tests_deferred_until_execution_or_repair',
                    'required_gates_deferred_until_verification',
                ],
                'deferred_context' => [
                    'required_tests_count' => count($requiredTests),
                    'required_gates_count' => count($requiredGates),
                ],
                'outputs' => ['command_ledger', 'diff_ledger', 'test_ledger'],
                'completion_gate' => 'aver_ledgers_green',
            ],
            [
                'step_id' => 'S4',
                'name' => 'repair_or_block',
                'owner_role' => 'repairer',
                'inputs' => ['failure_packet', 'causal_context_expansion_on_failure'],
                'deferred_context' => [
                    'causal_path_count' => count($causalPaths),
                    'load_paths_only_after_failure_or_ambiguous_blast_radius' => true,
                ],
                'outputs' => ['repair_cycle_or_blocker'],
                'completion_gate' => 'repair_evidence_recorded_when_needed',
            ],
            [
                'step_id' => 'S5',
                'name' => 'certify_and_learn',
                'owner_role' => 'reviewer',
                'inputs' => ['certified_execution', 'aemor_outcome_bridge'],
                'outputs' => ['gold_certification_or_blocked_reason', 'outcome_learning'],
                'completion_gate' => 'certification_and_outcome_recorded',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $allowedWritePaths
     * @param  array<int,string>  $readFirst
     * @param  array<int,string>  $requiredTests
     * @param  array<int,string>  $requiredGates
     * @param  array<int,array<string,mixed>>  $causalPaths
     * @return array<int,array<string,mixed>>
     */
    private function multiAgentSchedule(array $allowedWritePaths, array $readFirst, array $requiredTests, array $requiredGates, array $causalPaths): array
    {
        return [
            [
                'agent_role' => 'architect',
                'authority' => 'read_only_context_and_boundary',
                'responsibilities' => ['review_owner_docs', 'validate_causal_paths', 'confirm_allowed_write_paths'],
                'context_refs' => array_slice($readFirst, 0, 5),
                'deferred_context_count' => max(0, count($readFirst) - 5),
            ],
            [
                'agent_role' => 'implementer',
                'authority' => 'patch_proposal_only_until_aver',
                'responsibilities' => ['edit_only_allowed_paths', 'produce_action_manifest', 'preserve_user_changes'],
                'allowed_write_paths' => $allowedWritePaths,
            ],
            [
                'agent_role' => 'verifier',
                'authority' => 'aver_command_diff_test_ledgers',
                'responsibilities' => ['run_required_gates', 'verify_diff', 'certify_or_block'],
                'required_tests_count' => count($requiredTests),
                'required_gates_count' => count($requiredGates),
                'expansion_command' => 'php artisan atlas:verified-evolution proof-plan --target="<target>" --objective="<objective>" --json',
            ],
            [
                'agent_role' => 'repairer',
                'authority' => 'repair_cycle_only_after_failure',
                'responsibilities' => ['derive_failure_packet', 'reuse_causal_context', 'request_rerun'],
                'causal_path_count' => count($causalPaths),
                'expansion_policy' => 'load_causal_paths_only_after_failure_or_uncertain_blast_radius',
            ],
            [
                'agent_role' => 'reviewer',
                'authority' => 'human_or_policy_review_before_live_claim',
                'responsibilities' => ['check_certification', 'record_outcome', 'reject_unproven_completion_claims'],
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $contracts
     * @return array<int,string>
     */
    private function blockersFrom(array $contracts): array
    {
        $blockers = [];
        foreach ($contracts as $name => $contract) {
            if (($contract['status'] ?? null) === 'blocked') {
                $blockers[] = $name.'_blocked';
            }
            foreach ((array) ($contract['blockers'] ?? []) as $blocker) {
                if (is_array($blocker)) {
                    $blockers[] = $name.':'.(string) ($blocker['reason'] ?? 'blocked');
                } elseif (is_string($blocker) && $blocker !== '') {
                    $blockers[] = $name.':'.$blocker;
                }
            }
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>
     */
    private function contractSummaries(array $executionContract, array $proofPlan, array $patchSimulation, array $productDelivery, array $averPlan): array
    {
        return [
            'detail_level' => 'summary',
            'full_contracts_deferred' => true,
            'expand_with' => '--include-contracts',
            'verified_evolution_execution_contract' => [
                'status' => $executionContract['status'] ?? null,
                'hash' => data_get($executionContract, 'execution_contract.contract_hash'),
                'allowed_write_path_count' => count((array) data_get($executionContract, 'execution_contract.aver_plan_input.allowed_write_paths', [])),
                'read_first_count' => count((array) data_get($executionContract, 'execution_contract.aver_plan_input.read_first', [])),
            ],
            'verified_evolution_proof_plan' => [
                'status' => $proofPlan['status'] ?? null,
                'hash' => data_get($proofPlan, 'proof_plan.proof_plan_hash'),
                'required_test_count' => count((array) data_get($proofPlan, 'proof_plan.required_tests', [])),
                'required_gate_count' => count((array) data_get($proofPlan, 'proof_plan.required_gates', [])),
                'causal_path_count' => count((array) data_get($proofPlan, 'proof_plan.causal_verification.causal_paths', [])),
            ],
            'verified_evolution_patch_simulation' => [
                'status' => $patchSimulation['status'] ?? null,
                'hash' => data_get($patchSimulation, 'patch_simulation.simulation_hash'),
                'blocker_count' => count((array) ($patchSimulation['blockers'] ?? [])),
            ],
            'product_delivery_plan' => [
                'status' => $productDelivery['status'] ?? null,
                'route' => $productDelivery['route'] ?? null,
                'delivery_hash' => $productDelivery['delivery_hash'] ?? null,
                'business_twin_status' => data_get($productDelivery, 'product_twin_simulation.status'),
            ],
            'aver_plan' => [
                'status' => $averPlan['status'] ?? null,
                'execution_id' => $averPlan['execution_id'] ?? null,
                'writes' => (bool) ($averPlan['writes'] ?? false),
                'execution_hash' => $averPlan['execution_hash'] ?? null,
            ],
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return EngineeringStringListNormalizer::uniqueNonEmptyStrings((array) $value);
    }
}
