<?php

namespace Tests\Feature\Ai\Product;

use App\Models\AtlasProductDeliveryOutcomeMemory;
use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryCertificationService;
use App\Services\Ai\Product\AtlasProductDeliveryControlPlaneService;
use App\Services\Ai\Product\AtlasProductDeliveryDoctrineFitnessService;
use App\Services\Ai\Product\AtlasProductDeliveryEnforcementService;
use App\Services\Ai\Product\AtlasProductDeliveryEvidenceReplayLabService;
use App\Services\Ai\Product\AtlasProductDeliveryMultiStepRepairPlannerService;
use App\Services\Ai\Product\AtlasProductDeliveryMutativeRepairExecutorService;
use App\Services\Ai\Product\AtlasProductDeliveryOutcomeMemoryService;
use App\Services\Ai\Product\AtlasProductDeliveryPatchProposalGateService;
use App\Services\Ai\Product\AtlasProductDeliveryPatchRequestContractService;
use App\Services\Ai\Product\AtlasProductDeliveryPolicyOptimizerService;
use App\Services\Ai\Product\AtlasProductDeliveryProviderMemoryFeedService;
use App\Services\Ai\Product\AtlasProductDeliveryRiskGovernorService;
use App\Services\Ai\Product\AtlasProductDeliveryRuntimeReceiptService;
use App\Services\Ai\Product\AtlasProductExecutionPrimitivesService;
use App\Services\Ai\Product\AtlasProductFalsificationProofRuntimeService;
use App\Services\Ai\Product\AtlasProductReleaseGateService;
use App\Services\Ai\Product\AtlasProductTruthCompilerService;
use App\Services\Ai\Product\AtlasProductTwinSimulationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

class AtlasExecutionDoctrineProductDeliverySystemTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_05_22_171000_create_atlas_product_delivery_outcome_memories.php');
        $migration->up();
        $receiptMigration = require database_path('migrations/2026_05_22_172000_create_atlas_product_delivery_runtime_receipts.php');
        $receiptMigration->up();
        AtlasProductDeliveryOutcomeMemory::query()->delete();
        DB::table('atlas_product_delivery_runtime_receipts')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropAemorTables();

        parent::tearDown();
    }

    public function test_ecommerce_request_compiles_product_truth_with_enterprise_lenses(): void
    {
        $truth = app(AtlasProductTruthCompilerService::class)->compile([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
        ]);

        $this->assertSame(AtlasProductTruthCompilerService::SCHEMA_VERSION, $truth['schema_version']);
        $this->assertSame('ready', $truth['status']);
        $this->assertSame('product', $truth['product_intent']['kind']);
        $this->assertSame('atlas_forge', $truth['execution_decomposition']['route']);
        $this->assertTrue($truth['execution_decomposition']['requires_apfpr']);
        $this->assertContains('ddd', $truth['execution_lenses']['required']);
        $this->assertContains('atdd', $truth['execution_lenses']['required']);
        $this->assertContains('cdd', $truth['execution_lenses']['required']);
        $this->assertContains('add', $truth['execution_lenses']['required']);
        $this->assertContains('security_driven', $truth['execution_lenses']['required']);
        $this->assertContains('contract', $truth['execution_lenses']['blocked_if_missing']);
        $this->assertContains('payment', $truth['business_domain']['objects']);
        $this->assertContains('webhook_event_contract', $truth['contract_map']['events']);
        $this->assertSame(64, strlen((string) $truth['truth_hash']));
    }

    public function test_login_bug_compiles_dev_route_with_security_and_risk_lenses(): void
    {
        $truth = app(AtlasProductTruthCompilerService::class)->compile([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $this->assertSame('ready', $truth['status']);
        $this->assertSame('bug', $truth['product_intent']['kind']);
        $this->assertSame('atlas_dev', $truth['execution_decomposition']['route']);
        $this->assertContains('security_driven', $truth['execution_lenses']['required']);
        $this->assertContains('risk_driven', $truth['execution_lenses']['required']);
        $this->assertContains('session', $truth['business_domain']['objects']);
    }

    public function test_apdr_plans_delivery_with_truth_assisted_execution_and_proof_preview(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'operator_approved' => true,
            'ux_expectations' => ['login visual regression expectation'],
        ]);

        $this->assertSame(AtlasAutonomousProductDeliveryRuntimeService::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame('ready_for_delivery', $plan['status']);
        $this->assertSame('shadow_provider_free', $plan['mode']);
        $this->assertSame('atlas_dev', $plan['route']);
        $this->assertSame(AtlasProductTruthCompilerService::SCHEMA_VERSION, $plan['product_truth']['schema_version']);
        $this->assertSame('ready_for_assisted_execution', $plan['assisted_execution']['status']);
        $this->assertSame(AtlasProductFalsificationProofRuntimeService::SCHEMA_VERSION, $plan['proof_preview']['schema_version']);
        $this->assertSame(AtlasProductTwinSimulationService::SCHEMA_VERSION, $plan['product_twin_simulation']['schema_version']);
        $this->assertSame('simulated', $plan['product_twin_simulation']['status']);
        $this->assertTrue($plan['proof_requirements']['product_twin_simulation_required']);
        $this->assertSame(AtlasProductDeliveryRiskGovernorService::SCHEMA_VERSION, $plan['risk_governor']['schema_version']);
        $this->assertContains($plan['risk_governor']['status'], ['allowed', 'blocked']);
        $this->assertTrue($plan['proof_requirements']['risk_governor_required']);
        $this->assertSame(AtlasProductDeliveryEnforcementService::SCHEMA_VERSION, $plan['enforcement']['schema_version']);
        $this->assertSame('allowed', $plan['enforcement']['status']);
        $this->assertTrue($plan['enforcement']['provider_execution_allowed']);
        $this->assertSame('atlas.product_delivery.repair_bridge.v1', $plan['repair_bridge']['schema_version']);
        $this->assertSame('repair_required', $plan['repair_bridge']['status']);
        $this->assertSame('atlas.programming.dev_repair_receipt.v1', data_get($plan, 'repair_bridge.repair.schema_version'));
        $this->assertSame(AtlasProductDeliveryMultiStepRepairPlannerService::SCHEMA_VERSION, $plan['multi_step_repair_plan']['schema_version']);
        $this->assertSame('planned', $plan['multi_step_repair_plan']['status']);
        $this->assertFalse($plan['claim_policy']['provider_invoked']);
        $this->assertFalse($plan['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $plan['delivery_hash']));
    }

    public function test_product_twin_simulates_contract_test_and_risk_before_execution(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $simulation = app(AtlasProductTwinSimulationService::class)->simulate([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'patch_manifest' => [
                'operations' => [[
                    'path' => 'app/Services/Ai/Product/FakeCheckout.php',
                    'content' => '<?php',
                ]],
            ],
        ]);

        $this->assertSame(AtlasProductTwinSimulationService::SCHEMA_VERSION, $simulation['schema_version']);
        $this->assertSame('simulated', $simulation['status']);
        $this->assertSame('provider_free_read_only', $simulation['mode']);
        $this->assertSame('atlas_forge', $simulation['route_fit']['expected_route']);
        $this->assertTrue($simulation['route_fit']['matches']);
        $this->assertSame('high', data_get($simulation, 'risk_forecast.risk_band'));
        $this->assertContains('contract tests', data_get($simulation, 'predicted_impact.tests.focused_tests'));
        $this->assertContains('webhook_event_contract', data_get($simulation, 'predicted_impact.contracts.events'));
        $this->assertTrue(data_get($simulation, 'patch_candidate_analysis.proposal_gate_required'));
        $this->assertFalse(data_get($simulation, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($simulation, 'claim_policy.writes'));
        $this->assertSame(64, strlen((string) $simulation['simulation_hash']));
    }

    public function test_product_twin_blocks_route_mismatch_before_execution(): void
    {
        $truth = app(AtlasProductTruthCompilerService::class)->compile([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
        ]);

        $simulation = app(AtlasProductTwinSimulationService::class)->simulate([
            'product_truth' => $truth,
            'delivery_contract' => ['route' => 'atlas_dev'],
        ]);

        $this->assertSame('blocked', $simulation['status']);
        $this->assertContains('route_mismatch', array_column(data_get($simulation, 'risk_forecast.blockers', []), 'id'));
    }

    public function test_product_delivery_risk_governor_blocks_high_risk_without_operator_approval(): void
    {
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
        ]);

        $risk = app(AtlasProductDeliveryRiskGovernorService::class)->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
        );

        $this->assertSame(AtlasProductDeliveryRiskGovernorService::SCHEMA_VERSION, $risk['schema_version']);
        $this->assertSame('blocked', $risk['status']);
        $this->assertContains($risk['risk_band'], ['high', 'critical']);
        $this->assertContains('operator_delivery_risk_acceptance', $risk['required_approvals']);
        $this->assertContains('forge_scope_approval', $risk['required_approvals']);
        $this->assertFalse(data_get($risk, 'autonomy_budget.provider_plan_allowed'));
        $this->assertContains('human_approval_required', array_column($risk['blockers'], 'id'));
        $this->assertFalse($risk['claim_policy']['provider_invoked']);
        $this->assertFalse($risk['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $risk['risk_governor_hash']));
    }

    public function test_product_delivery_risk_governor_allows_operator_approved_provider_patch_with_required_gates(): void
    {
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'provider_patch' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $risk = app(AtlasProductDeliveryRiskGovernorService::class)->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
            options: [
                'operator_approved' => true,
                'provider_patch' => true,
            ],
        );

        $this->assertSame('allowed', $risk['status']);
        $this->assertTrue(data_get($risk, 'autonomy_budget.provider_plan_allowed'));
        $this->assertTrue(data_get($risk, 'autonomy_budget.provider_patch_apply_allowed'));
        $this->assertContains('patch_proposal_gate', $risk['required_gates']);
        $this->assertContains('rollback_snapshot', $risk['required_gates']);
    }

    public function test_product_delivery_risk_governor_blocks_unsafe_runtime_receipt_history(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('repair_execution', [
            'schema_version' => 'atlas.product_delivery.mutative_repair_executor.v1',
            'status' => 'applied',
            'route' => 'atlas_dev',
            'writes' => true,
            'patch_manifest' => ['files' => []],
        ]);
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'operator_approved' => true,
        ]);

        $risk = app(AtlasProductDeliveryRiskGovernorService::class)->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
            options: ['operator_approved' => true],
        );

        $this->assertSame('blocked', $risk['status']);
        $this->assertContains('unsafe_write_receipt_history', $risk['risk_factors']);
        $this->assertContains('unsafe_write_receipts_detected', array_column($risk['blockers'], 'id'));
        $this->assertContains('runtime_receipt_audit', $risk['required_gates']);
        $this->assertSame('plan_only', data_get($risk, 'governor_decision.max_autonomy_level'));
    }

    public function test_product_delivery_risk_governor_reduces_autonomy_from_doctrine_fitness_pressure(): void
    {
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);
        app(AtlasProductDeliveryOutcomeMemoryService::class)->persist(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            evidence: ['tests' => ['focused tests failed']],
        );
        $fitness = app(AtlasProductDeliveryDoctrineFitnessService::class)->evaluate();

        $risk = app(AtlasProductDeliveryRiskGovernorService::class)->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
            options: [
                'operator_approved' => true,
                'doctrine_fitness' => $fitness,
            ],
        );

        $this->assertSame('allowed', $risk['status']);
        $this->assertContains('doctrine_fitness_policy_pressure', $risk['risk_factors']);
        $this->assertContains('low_doctrine_fitness_score', $risk['risk_factors']);
        $this->assertContains('doctrine_fitness_review', $risk['required_gates']);
        $this->assertFalse(data_get($risk, 'governor_decision.may_increase_autonomy'));
    }

    public function test_apfpr_blocks_complex_delivery_without_evidence(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $this->assertSame('repair_required', $plan['repair_bridge']['status']);
        $this->assertSame('atlas_forge', $plan['repair_bridge']['route']);
        $this->assertSame('atlas.forge.apfpr_repair_packet.v1', data_get($plan, 'repair_bridge.repair.schema_version'));
        $this->assertTrue((bool) data_get($plan, 'repair_bridge.repair.next_packet_required'));

        $proof = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'evidence' => [],
        ]);

        $this->assertSame('blocked', $proof['status']);
        $this->assertContains('missing_test_evidence', array_column($proof['critical_blockers'], 'id'));
        $this->assertContains('missing_security_evidence', array_column($proof['critical_blockers'], 'id'));
        $this->assertContains('missing_acceptance_mapping', array_column($proof['critical_blockers'], 'id'));
        $this->assertContains('add_or_run_focused_tests', $proof['required_repairs']);
        $this->assertSame('failed', $proof['proof']['tests']);
    }

    public function test_multi_step_repair_planner_orders_evidence_patch_and_proof_steps(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $repairPlan = app(AtlasProductDeliveryMultiStepRepairPlannerService::class)->plan(
            $plan,
            $plan['proof_preview'],
            $plan['repair_bridge'],
        );
        $stepIds = array_column($repairPlan['steps'], 'id');

        $this->assertSame(AtlasProductDeliveryMultiStepRepairPlannerService::SCHEMA_VERSION, $repairPlan['schema_version']);
        $this->assertSame('planned', $repairPlan['status']);
        $this->assertContains('context_refresh', $stepIds);
        $this->assertContains('patch_request_projection', $stepIds);
        $this->assertContains('patch_proposal_gate', $stepIds);
        $this->assertContains('dry_run_repair', $stepIds);
        $this->assertContains('apply_and_verify', $stepIds);
        $this->assertContains('persist_outcome', $stepIds);
        $this->assertTrue(data_get($repairPlan, 'rollback_policy.required_before_any_write'));
        $this->assertContains('budget_exhausted', $repairPlan['stop_conditions']);
        $this->assertFalse(data_get($repairPlan, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($repairPlan, 'claim_policy.writes'));
        $this->assertSame(64, strlen((string) $repairPlan['repair_plan_hash']));
    }

    public function test_apfpr_accepts_delivery_with_sufficient_evidence(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $proof = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'evidence' => [
                'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
                'security' => ['abuse cases reviewed'],
                'acceptance_mapping' => ['tests mapped to acceptance'],
                'outcome' => ['outcome memory candidate recorded'],
            ],
        ]);

        $this->assertSame('ready', $proof['status']);
        $this->assertSame([], $proof['critical_blockers']);
        $this->assertSame('passed', $proof['proof']['requirements']);
        $this->assertSame('passed', $proof['proof']['contracts']);
        $this->assertSame('passed', $proof['proof']['security']);
        $this->assertSame('passed', $proof['proof']['tests']);
        $this->assertSame(64, strlen((string) $proof['proof_hash']));
    }

    public function test_post_execution_enforcement_blocks_high_risk_without_ready_proof_and_outcome_memory(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);
        $proof = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'evidence' => [],
        ]);

        $enforcement = app(AtlasProductDeliveryEnforcementService::class)->evaluate($plan, $proof, [
            'phase' => 'post_execution',
            'outcome_memory_recorded' => false,
        ]);

        $this->assertSame(AtlasProductDeliveryEnforcementService::SCHEMA_VERSION, $enforcement['schema_version']);
        $this->assertSame('blocked', $enforcement['status']);
        $this->assertFalse($enforcement['completion_allowed']);
        $this->assertContains('apfpr_not_ready_for_high_risk_delivery', array_column($enforcement['blockers'], 'id'));
        $this->assertContains('missing_product_delivery_outcome_memory', array_column($enforcement['blockers'], 'id'));
        $this->assertSame(64, strlen((string) $enforcement['enforcement_hash']));
    }

    public function test_product_delivery_outcome_memory_persists_idempotently(): void
    {
        $evidence = [
            'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
            'security' => ['abuse cases reviewed'],
            'acceptance_mapping' => ['tests mapped to acceptance'],
            'outcome' => ['outcome memory recorded'],
        ];
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'evidence' => $evidence,
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);
        $proof = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'evidence' => $evidence,
        ]);

        $service = app(AtlasProductDeliveryOutcomeMemoryService::class);
        $first = $service->persist($plan, $proof, $evidence);
        $second = $service->persist($plan, $proof, $evidence);

        $this->assertInstanceOf(AtlasProductDeliveryOutcomeMemory::class, $first);
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame('ready', $first?->outcome_status);
        $this->assertContains('high_risk_product_delivery_requires_apfpr', $first?->learning_candidates ?? []);
        $this->assertSame(1, AtlasProductDeliveryOutcomeMemory::query()->count());
        $this->assertSame(64, strlen((string) $first?->outcome_memory_hash));
    }

    public function test_product_delivery_outcome_memory_bridges_to_aemor_learning_candidate(): void
    {
        $this->createAemorTables();
        $evidence = [
            'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
            'security' => ['abuse cases reviewed'],
            'acceptance_mapping' => ['tests mapped to acceptance'],
            'outcome' => ['outcome memory recorded'],
        ];
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'evidence' => $evidence,
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);
        $proof = app(AtlasProductFalsificationProofRuntimeService::class)->challenge([
            'product_truth' => $plan['product_truth'],
            'delivery_contract' => $plan,
            'evidence' => $evidence,
        ]);

        $service = app(AtlasProductDeliveryOutcomeMemoryService::class);
        $memory = $service->build($plan, $proof, $evidence);
        $record = $service->persist($plan, $proof, $evidence);
        $bridge = $service->bridgeToAemor($memory, $record);

        $this->assertSame('atlas.product_delivery.aemor_bridge.v1', $bridge['schema_version']);
        $this->assertSame('recorded', $bridge['status']);
        $this->assertTrue($bridge['writes']);
        $this->assertTrue($bridge['learning_allowed']);
        $this->assertSame('passed', $bridge['judgment_status']);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_execution_events', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
        $this->assertDatabaseCount('atlas_aemor_judgment_reports', 1);
        $this->assertDatabaseCount('atlas_aemor_learning_signals', 1);
        $this->assertDatabaseCount('atlas_aemor_memory_candidates', 1);
    }

    public function test_mutative_repair_executor_dry_run_creates_rollback_without_writes(): void
    {
        $target = 'storage/framework/testing/aedpds-mutative-dry-run.txt';
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');

        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);
        $proof = $plan['proof_preview'];

        $receipt = app(AtlasProductDeliveryMutativeRepairExecutorService::class)->execute(
            delivery: $plan,
            proof: $proof,
            repairBridge: $plan['repair_bridge'],
            patchManifest: [
                'allowed_files' => [$target],
                'operations' => [[
                    'path' => $target,
                    'expected_sha256' => hash('sha256', 'before'),
                    'content' => 'after',
                ]],
            ],
        );

        $this->assertSame(AtlasProductDeliveryMutativeRepairExecutorService::SCHEMA_VERSION, $receipt['schema_version']);
        $this->assertSame('dry_run_ready', $receipt['status']);
        $this->assertFalse($receipt['writes']);
        $this->assertSame('before', File::get(base_path($target)));
        $this->assertSame('before', data_get($receipt, 'rollback_plan.items.0.content_before'));
        $this->assertSame(64, strlen((string) $receipt['execution_receipt_hash']));

        File::delete(base_path($target));
    }

    public function test_mutative_repair_executor_blocks_disallowed_patch_path(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $receipt = app(AtlasProductDeliveryMutativeRepairExecutorService::class)->execute(
            delivery: $plan,
            proof: $plan['proof_preview'],
            repairBridge: $plan['repair_bridge'],
            patchManifest: [
                'allowed_files' => ['storage/framework/testing/allowed.txt'],
                'operations' => [[
                    'path' => 'storage/framework/testing/not-allowed.txt',
                    'content' => 'after',
                ]],
            ],
            options: ['apply' => true],
        );

        $this->assertSame('blocked', $receipt['status']);
        $this->assertFalse($receipt['writes']);
        $this->assertContains('path_not_allowed', array_column(data_get($receipt, 'preflight.blockers', []), 'id'));
    }

    public function test_mutative_repair_executor_applies_explicit_patch_and_reruns_proof(): void
    {
        $target = 'storage/framework/testing/aedpds-mutative-apply.txt';
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');

        $evidence = [
            'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
            'security' => ['abuse cases reviewed'],
            'acceptance_mapping' => ['tests mapped to acceptance'],
            'outcome' => ['outcome memory recorded'],
        ];
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $receipt = app(AtlasProductDeliveryMutativeRepairExecutorService::class)->execute(
            delivery: $plan,
            proof: $plan['proof_preview'],
            repairBridge: $plan['repair_bridge'],
            patchManifest: [
                'allowed_files' => [$target],
                'operations' => [[
                    'path' => $target,
                    'expected_sha256' => hash('sha256', 'before'),
                    'content' => 'after',
                ]],
            ],
            options: [
                'apply' => true,
                'proof_evidence' => $evidence,
            ],
        );

        $this->assertSame('applied_and_verified', $receipt['status']);
        $this->assertTrue($receipt['writes']);
        $this->assertSame('after', File::get(base_path($target)));
        $this->assertSame('ready', data_get($receipt, 'proof_after_repair.status'));
        $this->assertTrue(data_get($receipt, 'claim_policy.rollback_required'));

        File::put(base_path($target), data_get($receipt, 'rollback_plan.items.0.content_before'));
        File::delete(base_path($target));
    }

    public function test_product_delivery_repair_execute_command_runs_dry_run_from_patch_manifest(): void
    {
        $target = 'storage/framework/testing/aedpds-mutative-command.txt';
        $manifest = storage_path('framework/testing/aedpds-mutative-command-manifest.json');
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');
        File::put($manifest, json_encode([
            'allowed_files' => [$target],
            'operations' => [[
                'path' => $target,
                'expected_sha256' => hash('sha256', 'before'),
                'content' => 'after',
            ]],
        ], JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:product-delivery:repair-execute', [
            'request' => 'estou com bug na tela de login',
            '--workspace' => 'atlas-app',
            '--patch' => $manifest,
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryMutativeRepairExecutorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('dry_run_ready', $payload['status']);
        $this->assertFalse($payload['writes']);
        $this->assertSame('before', File::get(base_path($target)));

        File::delete(base_path($target));
        File::delete($manifest);
    }

    public function test_patch_proposal_gate_blocks_provider_apply_without_human_approval(): void
    {
        $target = 'storage/framework/testing/aedpds-provider-patch-blocked.txt';
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $gate = app(AtlasProductDeliveryPatchProposalGateService::class)->evaluate($plan, [
            'allowed_files' => [$target],
            'operations' => [[
                'path' => $target,
                'content' => 'after',
            ]],
        ], [
            'source_kind' => 'provider',
            'apply' => true,
        ]);

        $this->assertSame(AtlasProductDeliveryPatchProposalGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertSame('needs_human_approval', $gate['status']);
        $this->assertFalse($gate['apply_allowed']);
        $this->assertTrue($gate['approval_required']);
        $this->assertContains('human_approval_required', array_column($gate['blockers'], 'id'));
        $this->assertFalse($gate['claim_policy']['auto_apply_provider_patch']);
        $this->assertSame(64, strlen((string) $gate['patch_gate_hash']));
    }

    public function test_patch_request_contract_projects_provider_safe_patch_schema(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $request = app(AtlasProductDeliveryPatchRequestContractService::class)->build(
            delivery: $plan,
            proof: $plan['proof_preview'],
            repairBridge: $plan['repair_bridge'],
            options: ['target' => 'subagent'],
        );

        $this->assertSame(AtlasProductDeliveryPatchRequestContractService::SCHEMA_VERSION, $request['schema_version']);
        $this->assertSame('ready_for_patch_proposal', $request['status']);
        $this->assertSame('subagent', $request['target']);
        $this->assertFalse($request['writes']);
        $this->assertFalse($request['claim_policy']['provider_invoked']);
        $this->assertSame('atlas.product_delivery.patch_manifest.v1', data_get($request, 'required_patch_manifest_schema.schema_version'));
        $this->assertContains('only_paths_in_allowed_files', data_get($request, 'required_patch_manifest_schema.rules'));
        $this->assertContains('missing_test_evidence', data_get($request, 'failure_summary.critical_blockers'));
        $this->assertContains('proof_rerun', data_get($request, 'acceptance_contract.required_evidence'));
        $this->assertSame('atlas.product_delivery.patch_prompt_projection.v1', data_get($request, 'prompt_projection.schema_version'));
        $this->assertContains('do_not_expand_scope', data_get($request, 'prompt_projection.non_goals'));
        $this->assertTrue(data_get($request, 'approval_policy.patch_proposal_gate_required'));
        $this->assertSame(64, strlen((string) $request['patch_request_hash']));
    }

    public function test_patch_request_and_repair_plan_block_when_delivery_contract_is_not_ready(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $request = app(AtlasProductDeliveryPatchRequestContractService::class)->build(
            delivery: $plan,
            proof: $plan['proof_preview'],
            repairBridge: $plan['repair_bridge'],
            options: ['target' => 'provider'],
        );
        $repairPlan = app(AtlasProductDeliveryMultiStepRepairPlannerService::class)->plan(
            $plan,
            $plan['proof_preview'],
            $plan['repair_bridge'],
        );

        $this->assertSame('blocked_by_aedpds_gate', $plan['status']);
        $this->assertSame('blocked', $request['status']);
        $this->assertContains('delivery_contract_not_ready', data_get($request, 'failure_summary.critical_blockers'));
        $this->assertSame('blocked', $repairPlan['status']);
        $this->assertContains('delivery_contract_not_ready', array_column($repairPlan['blockers'], 'id'));
    }

    public function test_product_delivery_patch_request_command_outputs_contract(): void
    {
        $exit = Artisan::call('atlas:product-delivery:patch-request', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--target' => 'provider',
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryPatchRequestContractService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_patch_proposal', $payload['status']);
        $this->assertSame('provider', $payload['target']);
        $this->assertSame('Return only an atlas.product_delivery.patch_manifest.v1 JSON object. Do not apply files. Do not run commands.', data_get($payload, 'prompt_projection.instruction'));
    }

    public function test_product_twin_command_outputs_simulation_json(): void
    {
        $exit = Artisan::call('atlas:product-twin:simulate', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductTwinSimulationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('simulated', $payload['status']);
        $this->assertSame('high', data_get($payload, 'risk_forecast.risk_band'));
    }

    public function test_product_delivery_risk_governor_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:risk-govern', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--provider-patch' => true,
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductDeliveryRiskGovernorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('allowed', $payload['status']);
        $this->assertContains('patch_proposal_gate', $payload['required_gates']);
    }

    public function test_product_delivery_control_plane_aggregates_delivery_risk_replay_fitness_and_certification(): void
    {
        $payload = app(AtlasProductDeliveryControlPlaneService::class)->snapshot([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'provider_patch' => true,
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);

        $this->assertSame(AtlasProductDeliveryControlPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame('atlas_forge', data_get($payload, 'delivery.route'));
        $this->assertSame('allowed', data_get($payload, 'risk_governor.status'));
        $this->assertSame('ready', data_get($payload, 'replay.status'));
        $this->assertSame('ready', data_get($payload, 'certification.status'));
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $payload['control_plane_hash']));
    }

    public function test_product_delivery_control_plane_blocks_when_replay_or_receipts_are_unsafe(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('repair_execution', [
            'schema_version' => 'atlas.product_delivery.mutative_repair_executor.v1',
            'status' => 'applied',
            'route' => 'atlas_dev',
            'writes' => true,
            'patch_manifest' => ['files' => []],
        ]);

        $payload = app(AtlasProductDeliveryControlPlaneService::class)->snapshot([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'operator_approved' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('evidence_replay_blocked', array_column($payload['blockers'], 'id'));
        $this->assertContains('risk_governor_blocked', array_column($payload['blockers'], 'id'));
        $this->assertSame(1, data_get($payload, 'replay.unsafe_write_receipt_count'));
    }

    public function test_product_delivery_control_plane_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:control-plane', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--provider-patch' => true,
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductDeliveryControlPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('healthy', $payload['status']);
    }

    public function test_provider_cost_flake_memory_feed_aggregates_receipts_and_outcomes(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('patch_request', [
            'schema_version' => 'atlas.product_delivery.patch_request_contract.v1',
            'status' => 'failed',
            'route' => 'atlas_dev',
            'provider' => 'claude_cli',
            'estimated_cost_usd' => 1.25,
            'test_results' => ['flake_count' => 2],
            'error' => 'provider timeout',
        ]);
        AtlasProductDeliveryOutcomeMemory::query()->create([
            'schema_version' => 'atlas.product_delivery.outcome_memory.v1',
            'uuid' => 'provider-memory-test',
            'delivery_hash' => str_repeat('a', 64),
            'truth_hash' => str_repeat('b', 64),
            'proof_hash' => str_repeat('c', 64),
            'route' => 'atlas_dev',
            'outcome_status' => 'needs_repair',
            'evidence_kinds' => ['tests'],
            'required_repairs' => ['rerun_flaky_test'],
            'learning_candidates' => [],
            'delivery_summary' => [],
            'proof_summary' => [],
            'should_promote_to_aemor' => false,
            'human_review_required' => true,
            'outcome_memory_hash' => str_repeat('d', 64),
        ]);

        $feed = app(AtlasProductDeliveryProviderMemoryFeedService::class)->analyze(['route' => 'atlas_dev']);

        $this->assertSame(AtlasProductDeliveryProviderMemoryFeedService::SCHEMA_VERSION, $feed['schema_version']);
        $this->assertSame('ready', $feed['status']);
        $this->assertSame(1, data_get($feed, 'sample.receipt_count'));
        $this->assertSame(1, data_get($feed, 'sample.outcome_count'));
        $this->assertSame('claude_cli', data_get($feed, 'providers.0.provider'));
        $this->assertSame(1, data_get($feed, 'providers.0.failure_count'));
        $this->assertSame(2, data_get($feed, 'risk_signals.flake_count'));
        $this->assertTrue(data_get($feed, 'risk_signals.cost_pressure'));
        $this->assertSame(64, strlen((string) $feed['provider_memory_hash']));
    }

    public function test_risk_governor_consumes_provider_memory_feed(): void
    {
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'evidence' => [
                'tests' => ['focused tests passed'],
                'security' => ['session risk reviewed'],
                'acceptance_mapping' => ['login acceptance mapped'],
                'outcome' => ['outcome memory candidate recorded'],
            ],
        ]);
        $feed = [
            'schema_version' => AtlasProductDeliveryProviderMemoryFeedService::SCHEMA_VERSION,
            'status' => 'ready',
            'risk_signals' => [
                'provider_failure_count' => 2,
                'cost_pressure' => true,
                'flake_count' => 1,
                'outcome_pressure_score' => 0.5,
            ],
        ];

        $risk = app(AtlasProductDeliveryRiskGovernorService::class)->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
            options: ['provider_memory_feed' => $feed, 'operator_approved' => true],
        );

        $this->assertContains('provider_failure_pressure', $risk['risk_factors']);
        $this->assertContains('cost_pressure', $risk['risk_factors']);
        $this->assertContains('test_flake_pressure', $risk['risk_factors']);
        $this->assertContains('provider_memory_review', $risk['required_gates']);
        $this->assertContains('flake_triage', $risk['required_gates']);
    }

    public function test_product_delivery_provider_memory_command_outputs_json(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('patch_request', [
            'schema_version' => 'atlas.product_delivery.patch_request_contract.v1',
            'status' => 'failed',
            'route' => 'atlas_dev',
            'provider' => 'codex_cli',
            'estimated_cost_usd' => 0.5,
            'flake_count' => 1,
        ]);

        $exit = Artisan::call('atlas:product-delivery:provider-memory', [
            '--route' => 'atlas_dev',
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductDeliveryProviderMemoryFeedService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1, data_get($payload, 'risk_signals.flake_count'));
    }

    public function test_product_policy_optimizer_proposes_guarded_changes_from_replay_fitness_and_provider_memory(): void
    {
        $payload = app(AtlasProductDeliveryPolicyOptimizerService::class)->propose([
            'replay_report' => ['status' => 'blocked'],
            'doctrine_fitness' => [
                'status' => 'ready',
                'policy_proposals' => [[
                    'proposal_type' => 'tighten_gate',
                    'reason' => 'Route fitness dropped after repeated repairs.',
                ]],
            ],
            'provider_memory_feed' => [
                'status' => 'ready',
                'risk_signals' => [
                    'provider_failure_count' => 1,
                    'flake_count' => 1,
                    'cost_pressure' => true,
                ],
            ],
        ]);

        $this->assertSame(AtlasProductDeliveryPolicyOptimizerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('proposal_ready', $payload['status']);
        $this->assertGreaterThanOrEqual(5, $payload['proposal_count']);
        $this->assertFalse(data_get($payload, 'policy_change_contract.auto_apply_allowed'));
        $this->assertTrue(data_get($payload, 'policy_change_contract.requires_aemor_judgment'));
        $this->assertTrue(data_get($payload, 'policy_change_contract.requires_human_review'));
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $payload['policy_optimizer_hash']));
    }

    public function test_product_delivery_policy_optimizer_command_outputs_json(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('patch_request', [
            'schema_version' => 'atlas.product_delivery.patch_request_contract.v1',
            'status' => 'failed',
            'route' => 'atlas_dev',
            'provider' => 'codex_cli',
            'flake_count' => 1,
        ]);

        $exit = Artisan::call('atlas:product-delivery:policy-optimizer', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductDeliveryPolicyOptimizerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ['watch', 'proposal_ready']);
        $this->assertFalse(data_get($payload, 'policy_change_contract.auto_apply_allowed'));
    }

    public function test_product_release_gate_allows_candidate_only_when_control_plane_is_green(): void
    {
        $payload = app(AtlasProductReleaseGateService::class)->decide([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'provider_patch' => true,
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
            'evidence' => [
                'tests' => ['focused tests passed', 'contract tests passed', 'security regression tests passed'],
                'security' => ['abuse cases reviewed'],
                'acceptance_mapping' => ['tests mapped to acceptance criteria'],
                'outcome' => ['outcome memory candidate recorded'],
            ],
        ]);

        $this->assertSame(AtlasProductReleaseGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('release_candidate_allowed', $payload['status']);
        $this->assertTrue($payload['release_candidate_allowed']);
        $this->assertSame('candidate', $payload['release_level']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse(data_get($payload, 'release_contract.may_publish_or_merge'));
        $this->assertTrue(data_get($payload, 'release_contract.requires_operator_release_approval'));
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $payload['release_gate_hash']));
    }

    public function test_product_release_gate_blocks_unsafe_replay_and_receipts(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('repair_execution', [
            'schema_version' => 'atlas.product_delivery.mutative_repair_executor.v1',
            'status' => 'applied',
            'route' => 'atlas_dev',
            'writes' => true,
            'patch_manifest' => ['files' => []],
        ]);

        $payload = app(AtlasProductReleaseGateService::class)->decide([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'operator_approved' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['release_candidate_allowed']);
        $this->assertContains('control_plane_not_healthy', array_column($payload['blockers'], 'id'));
        $this->assertContains('unsafe_write_receipts_detected', array_column($payload['blockers'], 'id'));
    }

    public function test_product_release_gate_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:release-gate', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--provider-patch' => true,
            '--operator-approved' => true,
            '--evidence-ready' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductReleaseGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertTrue($payload['release_candidate_allowed']);
    }

    public function test_product_delivery_repair_plan_command_outputs_multistep_plan(): void
    {
        $exit = Artisan::call('atlas:product-delivery:repair-plan', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProductDeliveryMultiStepRepairPlannerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('planned', $payload['status']);
        $this->assertGreaterThanOrEqual(3, count($payload['steps']));
    }

    public function test_evidence_replay_lab_replays_canonical_scenarios_without_writes(): void
    {
        $payload = app(AtlasProductDeliveryEvidenceReplayLabService::class)->replay([
            'workspace' => 'atlas-server',
        ]);

        $this->assertSame(AtlasProductDeliveryEvidenceReplayLabService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(3, $payload['scenario_count']);
        $this->assertSame(3, $payload['passed_scenario_count']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertSame('ready', data_get($payload, 'receipt_replay.status'));
        $this->assertSame(64, strlen((string) $payload['replay_hash']));
    }

    public function test_evidence_replay_lab_blocks_unsafe_write_receipt_without_approval(): void
    {
        app(AtlasProductDeliveryRuntimeReceiptService::class)->record('repair_execution', [
            'schema_version' => 'atlas.product_delivery.mutative_repair_executor.v1',
            'status' => 'applied',
            'route' => 'atlas_dev',
            'writes' => true,
            'patch_manifest' => ['files' => []],
        ]);

        $payload = app(AtlasProductDeliveryEvidenceReplayLabService::class)->replay();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(1, data_get($payload, 'receipt_replay.unsafe_write_receipt_count'));
        $this->assertContains('unsafe_write_receipts_detected', array_column($payload['blockers'], 'id'));
    }

    public function test_product_delivery_replay_lab_command_outputs_replay_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:replay-lab', [
            '--workspace' => 'atlas-server',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryEvidenceReplayLabService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }

    public function test_doctrine_fitness_loop_scores_routes_evidence_and_repairs_from_outcomes(): void
    {
        $delivery = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
        ]);
        app(AtlasProductDeliveryOutcomeMemoryService::class)->persist(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            evidence: ['tests' => ['focused tests failed']],
        );

        $payload = app(AtlasProductDeliveryDoctrineFitnessService::class)->evaluate();

        $this->assertSame(AtlasProductDeliveryDoctrineFitnessService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1, $payload['sample_size']);
        $this->assertSame('atlas_forge', data_get($payload, 'route_fitness.0.route'));
        $this->assertGreaterThan(0, data_get($payload, 'route_fitness.0.repair_pressure'));
        $this->assertSame('tests', data_get($payload, 'evidence_fitness.0.evidence_kind'));
        $this->assertFalse(data_get($payload, 'false_learning_guard.enforcement_allowed'));
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $payload['fitness_hash']));
    }

    public function test_product_delivery_doctrine_fitness_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:doctrine-fitness', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryDoctrineFitnessService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('watch', $payload['status']);
    }

    public function test_product_delivery_runtime_receipts_persist_patch_request_append_only(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
            'ux_expectations' => ['checkout journey expectation'],
        ]);
        $patchRequest = app(AtlasProductDeliveryPatchRequestContractService::class)->build(
            delivery: $plan,
            proof: $plan['proof_preview'],
            repairBridge: $plan['repair_bridge'],
        );

        $service = app(AtlasProductDeliveryRuntimeReceiptService::class);
        $record = $service->record('patch_request', $patchRequest);

        $this->assertInstanceOf(AtlasProductDeliveryRuntimeReceipt::class, $record);
        $this->assertSame('atlas.product_delivery.runtime_receipt.v1', $record->schema_version);
        $this->assertSame('patch_request', $record->receipt_type);
        $this->assertSame('ready_for_patch_proposal', $record->status);
        $this->assertFalse($record->writes);
        $this->assertSame(64, strlen((string) $record->receipt_hash));
        $this->assertSame(1, AtlasProductDeliveryRuntimeReceipt::query()->count());
    }

    public function test_product_delivery_patch_request_command_can_persist_receipt(): void
    {
        $exit = Artisan::call('atlas:product-delivery:patch-request', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--target' => 'provider',
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--persist' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('patch_request', data_get($payload, 'persisted_receipt.receipt_type'));
        $this->assertSame(1, AtlasProductDeliveryRuntimeReceipt::query()->count());
    }

    public function test_repair_execute_command_blocks_provider_apply_without_approval(): void
    {
        $target = 'storage/framework/testing/aedpds-provider-command-blocked.txt';
        $manifest = storage_path('framework/testing/aedpds-provider-command-blocked-manifest.json');
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');
        File::put($manifest, json_encode([
            'allowed_files' => [$target],
            'operations' => [[
                'path' => $target,
                'expected_sha256' => hash('sha256', 'before'),
                'content' => 'after',
            ]],
        ], JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:product-delivery:repair-execute', [
            'request' => 'estou com bug na tela de login',
            '--workspace' => 'atlas-app',
            '--patch' => $manifest,
            '--source' => 'provider',
            '--apply' => true,
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryPatchProposalGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('needs_human_approval', $payload['status']);
        $this->assertSame('before', File::get(base_path($target)));

        File::delete(base_path($target));
        File::delete($manifest);
    }

    public function test_repair_execute_command_applies_provider_patch_with_operator_approval(): void
    {
        $target = 'storage/framework/testing/aedpds-provider-command-approved.txt';
        $manifest = storage_path('framework/testing/aedpds-provider-command-approved-manifest.json');
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');
        File::put($manifest, json_encode([
            'allowed_files' => [$target],
            'operations' => [[
                'path' => $target,
                'expected_sha256' => hash('sha256', 'before'),
                'content' => 'after',
            ]],
        ], JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:product-delivery:repair-execute', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--patch' => $manifest,
            '--source' => 'provider',
            '--approval' => 'operator approved provider patch after review',
            '--apply' => true,
            '--evidence' => ['tests', 'security', 'acceptance_mapping', 'outcome'],
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryMutativeRepairExecutorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('applied_and_verified', $payload['status']);
        $this->assertSame('approved', data_get($payload, 'patch_proposal_gate.status'));
        $this->assertTrue(data_get($payload, 'patch_proposal_gate.approval_required'));
        $this->assertSame('after', File::get(base_path($target)));

        File::put(base_path($target), data_get($payload, 'rollback_plan.items.0.content_before'));
        File::delete(base_path($target));
        File::delete($manifest);
    }

    public function test_repair_execute_command_can_persist_gate_and_execution_receipts(): void
    {
        $target = 'storage/framework/testing/aedpds-provider-command-persisted.txt';
        $manifest = storage_path('framework/testing/aedpds-provider-command-persisted-manifest.json');
        File::ensureDirectoryExists(dirname(base_path($target)));
        File::put(base_path($target), 'before');
        File::put($manifest, json_encode([
            'allowed_files' => [$target],
            'operations' => [[
                'path' => $target,
                'expected_sha256' => hash('sha256', 'before'),
                'content' => 'after',
            ]],
        ], JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:product-delivery:repair-execute', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--patch' => $manifest,
            '--source' => 'provider',
            '--approval' => 'operator approved provider patch after review',
            '--apply' => true,
            '--persist' => true,
            '--evidence' => ['tests', 'security', 'acceptance_mapping', 'outcome'],
            '--ux' => ['checkout journey expectation'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('patch_gate', data_get($payload, 'persisted_receipts.patch_gate.receipt_type'));
        $this->assertSame('repair_execution', data_get($payload, 'persisted_receipts.repair_execution.receipt_type'));
        $this->assertSame(2, AtlasProductDeliveryRuntimeReceipt::query()->count());

        File::put(base_path($target), data_get($payload, 'rollback_plan.items.0.content_before'));
        File::delete(base_path($target));
        File::delete($manifest);
    }

    public function test_product_truth_compile_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-truth:compile', [
            'request' => 'cria um ecommerce completo',
            '--workspace' => 'atlas-server',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductTruthCompilerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('atlas_forge', $payload['execution_decomposition']['route']);
    }

    public function test_product_delivery_plan_command_strict_ready(): void
    {
        $exit = Artisan::call('atlas:product-delivery:plan', [
            'request' => 'estou com bug na tela de login',
            '--workspace' => 'atlas-app',
            '--operator-approved' => true,
            '--ux' => ['login visual regression expectation'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ready_for_delivery', $payload['status']);
    }

    public function test_product_execution_primitives_materialize_all_six_blocks_for_dev_request(): void
    {
        $report = app(AtlasProductExecutionPrimitivesService::class)->build([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
            'evidence' => [
                'tests' => ['focused login regression passed'],
                'acceptance_mapping' => ['login bug acceptance criteria mapped'],
                'outcome' => ['outcome candidate recorded'],
            ],
        ]);

        $this->assertSame(AtlasProductExecutionPrimitivesService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(6, $report['summary']['primitive_count']);
        $this->assertSame(['runtime_gate'], $report['summary']['blocked_primitives']);
        $this->assertSame('atlas_dev', data_get($report, 'request.route'));
        $this->assertSame(
            AtlasProductExecutionPrimitivesService::HUMAN_INTENT_MODEL_SCHEMA_VERSION,
            data_get($report, 'primitives.human_intent_model.schema_version'),
        );
        $this->assertSame('bug', data_get($report, 'primitives.human_intent_model.intent.kind'));
        $this->assertSame('high', data_get($report, 'primitives.human_intent_model.confidence'));
        $this->assertSame(
            AtlasProductTwinSimulationService::SCHEMA_VERSION,
            data_get($report, 'primitives.software_twin_simulation.schema_version'),
        );
        $this->assertSame(
            AtlasProductDeliveryOutcomeMemoryService::SCHEMA_VERSION,
            data_get($report, 'primitives.outcome_memory.schema_version'),
        );
        $this->assertSame(
            AtlasProductExecutionPrimitivesService::OPERATIONAL_CARTOGRAPHY_SCHEMA_VERSION,
            data_get($report, 'primitives.operational_cartography.schema_version'),
        );
        $this->assertContains(data_get($report, 'primitives.operational_cartography.status'), ['ready', 'review']);
        $this->assertSame(
            AtlasProductExecutionPrimitivesService::RUNTIME_GATE_SCHEMA_VERSION,
            data_get($report, 'primitives.runtime_gate.schema_version'),
        );
        $this->assertSame('blocked', data_get($report, 'primitives.runtime_gate.status'));
        $this->assertSame('blocked', data_get($report, 'primitives.runtime_gate.gate_decision'));
        $this->assertFalse(data_get($report, 'primitives.runtime_gate.provider_execution_allowed'));
        $this->assertSame(
            AtlasProductExecutionPrimitivesService::PROVIDER_AGENT_STRATEGY_SCHEMA_VERSION,
            data_get($report, 'primitives.provider_agent_strategy.schema_version'),
        );
        $this->assertSame('dev_with_senior_review_and_operator_gate', data_get($report, 'primitives.provider_agent_strategy.recommended_execution_mode'));
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['writes']);
        $this->assertSame(64, strlen((string) $report['execution_primitives_hash']));
    }

    public function test_product_execution_primitives_runtime_gate_blocks_high_risk_forge_without_approval(): void
    {
        $report = app(AtlasProductExecutionPrimitivesService::class)->build([
            'human_request' => 'cria um ecommerce completo com pagamentos e webhooks',
            'workspace' => 'atlas-server',
        ]);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('atlas_forge', data_get($report, 'request.route'));
        $this->assertSame('blocked', data_get($report, 'primitives.runtime_gate.status'));
        $this->assertSame('blocked', data_get($report, 'primitives.runtime_gate.gate_decision'));
        $this->assertFalse(data_get($report, 'primitives.runtime_gate.provider_execution_allowed'));
        $this->assertContains('operator_delivery_risk_acceptance', data_get($report, 'primitives.runtime_gate.required_approvals'));
        $this->assertSame(
            'forge_workcell_with_operator_gate',
            data_get($report, 'primitives.provider_agent_strategy.recommended_execution_mode'),
        );
    }

    public function test_product_execution_primitives_command_outputs_json(): void
    {
        $exit = Artisan::call('atlas:product-delivery:primitives', [
            'request' => 'estou com bug na tela de login',
            '--workspace' => 'atlas-app',
            '--operator-approved' => true,
            '--ux' => ['login visual regression expectation'],
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductExecutionPrimitivesService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(6, data_get($payload, 'summary.primitive_count'));
    }

    public function test_product_execution_primitives_command_strict_blocks_when_runtime_gate_blocks(): void
    {
        $exit = Artisan::call('atlas:product-delivery:primitives', [
            'request' => 'estou com bug na tela de login',
            '--workspace' => 'atlas-app',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'primitives.runtime_gate.status'));
        $this->assertSame(['runtime_gate'], data_get($payload, 'summary.blocked_primitives'));
    }

    public function test_product_proof_challenge_command_blocks_without_demo_evidence_in_strict_mode(): void
    {
        $exit = Artisan::call('atlas:product-proof:challenge', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('blocked', $payload['status']);
    }

    public function test_product_proof_challenge_command_can_pass_when_apdr_gate_and_evidence_are_ready(): void
    {
        $exit = Artisan::call('atlas:product-proof:challenge', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--operator-approved' => true,
            '--ux' => ['checkout journey expectation'],
            '--with-demo-evidence' => true,
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ready', $payload['status']);
    }

    public function test_product_delivery_certification_is_ready_and_command_is_strict_green(): void
    {
        $report = app(AtlasProductDeliveryCertificationService::class)->certify();

        $this->assertSame(AtlasProductDeliveryCertificationService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame(27, $report['summary']['total']);
        $this->assertSame(27, $report['summary']['passed']);
        $this->assertSame('passed', collect($report['checks'])->firstWhere('id', 'sample_apdr_blocks_when_aedpds_gate_blocks')['status']);
        $this->assertSame([], $report['remaining_blockers']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['writes']);

        $exit = Artisan::call('atlas:product-delivery:certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(64, strlen((string) $payload['certification_hash']));
    }

    public function test_product_delivery_outcome_command_can_persist_memory(): void
    {
        $exit = Artisan::call('atlas:product-delivery:outcome', [
            'request' => 'cria um ecommerce completo com pagamentos e webhooks',
            '--workspace' => 'atlas-server',
            '--status' => 'ready',
            '--evidence' => ['tests', 'security', 'acceptance_mapping', 'outcome'],
            '--persist' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasProductDeliveryOutcomeMemoryService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['outcome_status']);
        $this->assertTrue($payload['persisted']);
        $this->assertSame('allowed', $payload['completion_enforcement']['status']);
        $this->assertTrue($payload['completion_enforcement']['completion_allowed']);
        $this->assertSame(1, AtlasProductDeliveryOutcomeMemory::query()->count());
    }
}
