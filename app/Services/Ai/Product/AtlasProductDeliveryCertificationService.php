<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class AtlasProductDeliveryCertificationService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $sample = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
            'evidence' => [
                'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
                'security' => ['abuse cases reviewed'],
                'acceptance_mapping' => ['tests mapped to acceptance'],
                'outcome' => ['outcome memory candidate recorded'],
            ],
        ]);
        $blockedGateSample = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $checks = [
            $this->check('product_truth_compiler', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductTruthCompilerService.php',
                ['atlas.product_truth_contract.v1', 'execution_lenses', 'truth_hash'],
            )),
            $this->check('autonomous_product_delivery_runtime', $this->sourceHas(
                'app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php',
                ['atlas.autonomous_product_delivery_runtime.v1', 'proof_preview', 'enforcement', 'repair_bridge', 'ready_for_delivery', 'blocked_by_aedpds_gate'],
            )),
            $this->check('product_falsification_proof_runtime', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductFalsificationProofRuntimeService.php',
                ['atlas.product_proof_challenge.v1', 'critical_blockers', 'counterexamples', 'proof_hash', 'delivery_contract_not_ready'],
            )),
            $this->check('product_delivery_enforcement', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryEnforcementService.php',
                ['atlas.product_delivery.enforcement.v1', 'post_execution', 'apfpr_not_ready_for_high_risk_delivery'],
            )),
            $this->check('product_delivery_outcome_memory', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php',
                ['atlas.product_delivery.outcome_memory.v1', 'AtlasProductDeliveryOutcomeMemory', 'should_promote_to_aemor'],
            ) && $this->sourceHas(
                'database/migrations/2026_05_22_171000_create_atlas_product_delivery_outcome_memories.php',
                ['atlas_product_delivery_outcome_memories', 'outcome_memory_hash'],
            )),
            $this->check('product_delivery_aemor_bridge', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php',
                ['atlas.product_delivery.aemor_bridge.v1', 'bridgeToAemor', 'AtlasAemorJudgmentService'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_delivery_outcome_memory_bridges_to_aemor_learning_candidate', 'atlas_aemor_judgment_reports', 'atlas_aemor_memory_candidates'],
            )),
            $this->check('product_delivery_repair_bridge', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryRepairBridgeService.php',
                ['atlas.product_delivery.repair_bridge.v1', 'DevRepairLoopService', 'atlas.forge.apfpr_repair_packet.v1'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['atlas.programming.dev_repair_receipt.v1', 'atlas.forge.apfpr_repair_packet.v1'],
            )),
            $this->check('product_delivery_mutative_repair_executor', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryMutativeRepairExecutorService.php',
                ['atlas.product_delivery.mutative_repair_executor.v1', 'rollback_snapshot', 'proof_after_repair'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_mutative_repair_executor_applies_explicit_patch_and_reruns_proof', 'dry_run_ready', 'path_not_allowed'],
            )),
            $this->check('product_delivery_patch_request_contract', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryPatchRequestContractService.php',
                ['atlas.product_delivery.patch_request_contract.v1', 'atlas.product_delivery.patch_manifest.v1', 'patch_prompt_projection.v1'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryPatchRequestCommand.php',
                ['atlas:product-delivery:patch-request', 'provider-safe AEDPDS patch request', '--operator-approved', '--ux=*'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_patch_request_contract_projects_provider_safe_patch_schema', 'test_product_delivery_patch_request_command_outputs_contract'],
            )),
            $this->check('product_delivery_patch_proposal_gate', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryPatchProposalGateService.php',
                ['atlas.product_delivery.patch_proposal_gate.v1', 'patch_operator_decision.v1', 'auto_apply_provider_patch'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairExecuteCommand.php',
                ['AtlasProductDeliveryPatchProposalGateService', '--source=human', '--approval='],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_patch_proposal_gate_blocks_provider_apply_without_human_approval', 'test_repair_execute_command_applies_provider_patch_with_operator_approval'],
            )),
            $this->check('product_delivery_runtime_receipts', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryRuntimeReceiptService.php',
                ['atlas.product_delivery.runtime_receipt.v1', 'patch_request', 'repair_execution'],
            ) && $this->sourceHas(
                'app/Models/AtlasProductDeliveryRuntimeReceipt.php',
                ['append-only', 'atlas_product_delivery_runtime_receipts'],
            ) && $this->sourceHas(
                'database/migrations/2026_05_22_172000_create_atlas_product_delivery_runtime_receipts.php',
                ['atlas_product_delivery_runtime_receipts', 'receipt_hash'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_delivery_runtime_receipts_persist_patch_request_append_only', 'test_repair_execute_command_can_persist_gate_and_execution_receipts'],
            )),
            $this->check('product_twin_simulation', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductTwinSimulationService.php',
                ['atlas.product_twin_simulation.v1', 'predicted_impact', 'risk_forecast', 'simulation_hash'],
            ) && $this->sourceHas(
                'app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php',
                ['product_twin_simulation', 'product_twin_simulation_required', 'atlas:product-twin:simulate'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductTwinSimulateCommand.php',
                ['atlas:product-twin:simulate', 'Simulates AEDPDS product delivery impact'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_twin_simulates_contract_test_and_risk_before_execution', 'test_product_twin_command_outputs_simulation_json'],
            )),
            $this->check('product_delivery_risk_governor', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryRiskGovernorService.php',
                ['atlas.product_delivery.risk_governor.v1', 'autonomy_budget', 'runtime_signals', 'governor_decision', 'risk_governor_hash'],
            ) && $this->sourceHas(
                'app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php',
                ['risk_governor', 'risk_governor_required', 'atlas:product-delivery:risk-govern'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryRiskGovernorCommand.php',
                ['atlas:product-delivery:risk-govern', 'Evaluates AEDPDS delivery risk'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_delivery_risk_governor_blocks_high_risk_without_operator_approval', 'test_product_delivery_risk_governor_blocks_unsafe_runtime_receipt_history', 'test_product_delivery_risk_governor_reduces_autonomy_from_doctrine_fitness_pressure', 'test_product_delivery_risk_governor_command_outputs_json'],
            )),
            $this->check('product_delivery_control_plane', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryControlPlaneService.php',
                ['atlas.product_delivery.control_plane.v1', 'risk_governor', 'doctrine_fitness', 'control_plane_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryControlPlaneCommand.php',
                ['atlas:product-delivery:control-plane', 'Aggregates AEDPDS delivery'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_delivery_control_plane_aggregates_delivery_risk_replay_fitness_and_certification', 'test_product_delivery_control_plane_blocks_when_replay_or_receipts_are_unsafe', 'test_product_delivery_control_plane_command_outputs_json'],
            )),
            $this->check('product_release_gate', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductReleaseGateService.php',
                ['atlas.product_delivery.release_gate.v1', 'release_candidate_allowed', 'required_green_signals', 'release_gate_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductReleaseGateCommand.php',
                ['atlas:product-delivery:release-gate', 'Decides whether AEDPDS can create a release candidate'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_release_gate_allows_candidate_only_when_control_plane_is_green', 'test_product_release_gate_blocks_unsafe_replay_and_receipts', 'test_product_release_gate_command_outputs_json'],
            )),
            $this->check('provider_cost_flake_memory_feed', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryProviderMemoryFeedService.php',
                ['atlas.product_delivery.provider_cost_flake_memory.v1', 'provider_failure_count', 'cost_pressure', 'provider_memory_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryProviderMemoryCommand.php',
                ['atlas:product-delivery:provider-memory', 'provider, cost, and flake memory'],
            ) && $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryRiskGovernorService.php',
                ['provider_memory_feed', 'provider_failure_pressure', 'provider_memory_review'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_provider_cost_flake_memory_feed_aggregates_receipts_and_outcomes', 'test_risk_governor_consumes_provider_memory_feed', 'test_product_delivery_provider_memory_command_outputs_json'],
            )),
            $this->check('product_execution_primitives', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductExecutionPrimitivesService.php',
                ['atlas.product_execution_primitives.v1', 'human_intent_model', 'software_twin_simulation', 'outcome_memory', 'operational_cartography', 'runtime_gate', 'provider_agent_strategy'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductExecutionPrimitivesCommand.php',
                ['atlas:product-delivery:primitives', 'intent, twin, outcome, cartography, gate, and provider strategy'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_execution_primitives_materialize_all_six_blocks_for_dev_request', 'test_product_execution_primitives_runtime_gate_blocks_high_risk_forge_without_approval', 'test_product_execution_primitives_command_outputs_json'],
            )),
            $this->check('product_policy_optimizer', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryPolicyOptimizerService.php',
                ['atlas.product_delivery.policy_optimizer.v1', 'requires_aemor_judgment', 'policy_optimizer_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryPolicyOptimizerCommand.php',
                ['atlas:product-delivery:policy-optimizer', 'guarded AEDPDS policy improvements'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_product_policy_optimizer_proposes_guarded_changes_from_replay_fitness_and_provider_memory', 'test_product_delivery_policy_optimizer_command_outputs_json'],
            )),
            $this->check('product_delivery_multi_step_repair_planner', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryMultiStepRepairPlannerService.php',
                ['atlas.product_delivery.multi_step_repair_plan.v1', 'rollback_policy', 'stop_conditions', 'repair_plan_hash'],
            ) && $this->sourceHas(
                'app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php',
                ['multi_step_repair_plan', 'AtlasProductDeliveryMultiStepRepairPlannerService'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairPlanCommand.php',
                ['atlas:product-delivery:repair-plan', 'multi-step repair plan', '--operator-approved', '--ux=*'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_multi_step_repair_planner_orders_evidence_patch_and_proof_steps', 'test_product_delivery_repair_plan_command_outputs_multistep_plan'],
            )),
            $this->check('product_delivery_evidence_replay_lab', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryEvidenceReplayLabService.php',
                ['atlas.product_delivery.evidence_replay_lab.v1', 'scenario_replay_failed', 'unsafe_write_receipts_detected', 'replay_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryReplayLabCommand.php',
                ['atlas:product-delivery:replay-lab', 'Replays canonical AEDPDS delivery scenarios'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_evidence_replay_lab_replays_canonical_scenarios_without_writes', 'test_product_delivery_replay_lab_command_outputs_replay_json'],
            )),
            $this->check('product_delivery_doctrine_fitness_loop', $this->sourceHas(
                'app/Services/Ai/Product/AtlasProductDeliveryDoctrineFitnessService.php',
                ['atlas.product_delivery.doctrine_fitness.v1', 'route_fitness', 'false_learning_guard', 'fitness_hash'],
            ) && $this->sourceHas(
                'app/Console/Commands/Ai/Product/AtlasProductDeliveryDoctrineFitnessCommand.php',
                ['atlas:product-delivery:doctrine-fitness', 'Evaluates AEDPDS doctrine fitness'],
            ) && $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['test_doctrine_fitness_loop_scores_routes_evidence_and_repairs_from_outcomes', 'test_product_delivery_doctrine_fitness_command_outputs_json'],
            )),
            $this->check('cli_commands', $this->sourceHas(
                'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php',
                ['atlas:product-truth:compile', 'atlas:product-delivery:plan', 'atlas:product-twin:simulate', 'atlas:product-delivery:risk-govern', 'atlas:product-delivery:control-plane', 'atlas:product-delivery:release-gate', 'atlas:product-delivery:provider-memory', 'atlas:product-delivery:policy-optimizer', 'atlas:product-delivery:repair-plan', 'atlas:product-delivery:replay-lab', 'atlas:product-delivery:doctrine-fitness', 'atlas:product-proof:challenge', 'atlas:product-delivery:patch-request', 'atlas:product-delivery:repair-execute', 'atlas:product-delivery:outcome', 'completion_enforcement'],
            )),
            $this->check('canonical_docs', $this->sourceHas(
                'docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md',
                ['APTC compila a verdade do produto', 'APDR e o motor que executa a regra', 'APFPR tenta provar que a entrega esta errada'],
            ) && $this->sourceHas(
                'docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md',
                ['Proof Challenge Report', 'False Completion Gate'],
            )),
            $this->check('sample_ecommerce_delivery_routes_to_forge', ($sample['route'] ?? null) === 'atlas_forge'),
            $this->check('sample_ecommerce_requires_apfpr', data_get($sample, 'proof_requirements.apfpr_required') === true),
            $this->check('sample_pre_provider_enforcement_allows_delivery', data_get($sample, 'enforcement.status') === 'allowed'
                && data_get($sample, 'enforcement.provider_execution_allowed') === true),
            $this->check('sample_apdr_blocks_when_aedpds_gate_blocks', ($blockedGateSample['status'] ?? null) === 'blocked_by_aedpds_gate'
                && data_get($blockedGateSample, 'aedpds.gate.status') === 'blocked'
                && data_get($blockedGateSample, 'enforcement.status') === 'blocked'
                && collect(data_get($blockedGateSample, 'proof_preview.critical_blockers', []))->pluck('id')->contains('delivery_contract_not_ready')),
        ];

        $failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : 'blocked',
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown'),
                $failed,
            ),
            'sample' => [
                'route' => $sample['route'] ?? null,
                'status' => $sample['status'] ?? null,
                'truth_schema' => data_get($sample, 'product_truth.schema_version'),
                'proof_schema' => data_get($sample, 'proof_preview.schema_version'),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'external_superiority_claim' => false,
            ],
        ];
        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => 'critical',
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function sourceHas(string $relative, array $needles): bool
    {
        $path = base_path($relative);
        $source = is_file($path) ? File::get($path) : '';

        foreach ($needles as $needle) {
            if (! str_contains($source, $needle)) {
                return false;
            }
        }

        return true;
    }
}
