<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Repair\DevRepairLoopService;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureModeClassifier;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairAttemptLimits;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;

class AtlasAutonomousProductDeliveryRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.autonomous_product_delivery_runtime.v1';

    public function __construct(
        private readonly AtlasProductTruthCompilerService $truthCompiler = new AtlasProductTruthCompilerService,
        private readonly AtlasAiAssistedExecutionQualityService $assistedExecution = new AtlasAiAssistedExecutionQualityService,
        private readonly AtlasProductFalsificationProofRuntimeService $proofRuntime = new AtlasProductFalsificationProofRuntimeService,
        private readonly AtlasProductDeliveryEnforcementService $enforcement = new AtlasProductDeliveryEnforcementService,
        private readonly AtlasProductTwinSimulationService $productTwin = new AtlasProductTwinSimulationService,
        private readonly AtlasProductDeliveryRiskGovernorService $riskGovernor = new AtlasProductDeliveryRiskGovernorService,
        private readonly AtlasExecutionDoctrineRuntimeService $executionDoctrine = new AtlasExecutionDoctrineRuntimeService,
        private readonly AtlasExecutionDoctrineGateService $executionDoctrineGate = new AtlasExecutionDoctrineGateService,
        private readonly AtlasProductDeliveryMultiStepRepairPlannerService $repairPlanner = new AtlasProductDeliveryMultiStepRepairPlannerService,
        private readonly AtlasProductDeliveryRepairBridgeService $repairBridge = new AtlasProductDeliveryRepairBridgeService(
            new DevRepairLoopService(
                new FailureCapsuleBuilder(
                    new FailureSignatureHasher,
                ),
                new FailureModeClassifier,
                new ProgrammingRepairExecutor,
                new ProgrammingTestImpactAnalyzer,
                new RepairAttemptLimits,
            ),
        ),
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $truth = $this->truthCompiler->compile($input);
        $request = $this->string($input['human_request'] ?? $input['input_text'] ?? $input['prompt'] ?? null)
            ?? 'Atlas product delivery request';
        $requestedRoute = $this->string($input['target'] ?? $input['route'] ?? null);
        $route = in_array($requestedRoute, ['atlas_dev', 'atlas_forge'], true)
            ? $requestedRoute
            : (string) data_get($truth, 'execution_decomposition.route', 'atlas_dev');
        data_set($truth, 'execution_decomposition.route', $route);
        $workspace = $this->string($input['workspace'] ?? $input['workspace_slug'] ?? null);
        $doctrine = $this->executionDoctrine->select([
            'task' => $request,
            'surface' => $route,
            'workspace' => $workspace,
            'task_type' => data_get($truth, 'product_intent.kind'),
            'risk_level' => $this->riskBand($truth),
            'code_changes_requested' => true,
            'missing_context' => ($truth['status'] ?? null) !== 'ready',
            'senior_review_present' => (bool) ($input['operator_approved'] ?? false),
            'context_refs' => $this->list($input['context_refs'] ?? []),
        ]);
        $doctrineGate = $this->executionDoctrineGate->evaluate([
            'doctrine' => $doctrine,
            'acceptance_criteria' => $this->list(data_get($truth, 'acceptance_universe.must_work', [])),
            'context_refs' => $this->list($input['context_refs'] ?? ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md']),
            'tests' => $this->testsFromTruth($truth),
            'contracts' => array_merge(
                $this->list(data_get($truth, 'contract_map.apis', [])),
                $this->list(data_get($truth, 'contract_map.events', [])),
                $this->list(data_get($truth, 'contract_map.data_shapes', [])),
            ),
            'docs' => $this->list($input['canonical_docs'] ?? ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md']),
            'review' => (bool) ($input['operator_approved'] ?? false) ? ['operator_or_senior_review'] : [],
            'evidence' => $this->list($input['evidence_refs'] ?? data_get($input, 'evidence.tests', [])),
            'ux_expectations' => $this->list($input['ux_expectations'] ?? []),
        ]);

        $assisted = $this->assistedExecution->buildEnvelope([
            'human_request' => $request,
            'workspace' => $workspace,
            'surface_id' => $this->string($input['surface_id'] ?? null) ?? 'atlas_ai',
            'route' => $route,
            'risk_band' => $this->riskBand($truth),
            'context_refs' => $this->list($input['context_refs'] ?? ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md']),
            'acceptance_criteria' => $this->list(data_get($truth, 'acceptance_universe.must_work', [])),
            'suggested_tests' => $this->testsFromTruth($truth),
        ]);

        $delivery = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($truth, $assisted),
            'mode' => 'shadow_provider_free',
            'route' => $route,
            'product_truth' => $truth,
            'aedpds' => [
                'doctrine' => $doctrine,
                'gate' => $doctrineGate,
            ],
            'assisted_execution' => $assisted,
            'delivery_plan' => $this->deliveryPlan($truth, $assisted),
            'proof_requirements' => [
                'apfpr_required' => (bool) data_get($truth, 'proof_plan.apfpr_required', false),
                'product_twin_simulation_required' => true,
                'product_twin_command' => 'php artisan atlas:product-twin:simulate --json',
                'risk_governor_required' => true,
                'risk_governor_command' => 'php artisan atlas:product-delivery:risk-govern --json',
                'proof_command' => 'php artisan atlas:product-proof:challenge --json',
                'certification_command' => 'php artisan atlas:product-delivery:certify --json',
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'external_superiority_claim' => false,
            ],
        ];

        $delivery['product_twin_simulation'] = $this->productTwin->simulate([
            'product_truth' => $truth,
            'delivery_contract' => $delivery,
            'patch_manifest' => $input['patch_manifest'] ?? [],
        ]);
        $delivery['proof_preview'] = $this->proofRuntime->challenge([
            'product_truth' => $truth,
            'delivery_contract' => $delivery,
            'evidence' => $input['evidence'] ?? [],
        ]);
        $delivery['risk_governor'] = $this->riskGovernor->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'],
            simulation: $delivery['product_twin_simulation'],
            options: [
                'phase' => 'pre_provider',
                'provider_patch' => (bool) ($input['provider_patch'] ?? false),
                'operator_approved' => (bool) ($input['operator_approved'] ?? false),
            ],
        );
        $delivery['enforcement'] = $this->enforcement->evaluate($delivery, $delivery['proof_preview'], [
            'phase' => 'pre_provider',
        ]);
        $delivery['repair_bridge'] = $this->repairBridge->plan($delivery, $delivery['proof_preview']);
        $delivery['multi_step_repair_plan'] = $this->repairPlanner->plan($delivery, $delivery['proof_preview'], $delivery['repair_bridge']);
        $delivery['delivery_hash'] = MissionCanonicalHash::sha256($delivery);

        return $delivery;
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $assisted
     * @return array<string,mixed>
     */
    private function deliveryPlan(array $truth, array $assisted): array
    {
        $route = (string) data_get($truth, 'execution_decomposition.route', 'atlas_dev');

        return [
            'execution_unit' => $route === 'atlas_forge' ? 'forge_obra' : 'dev_task',
            'required_lenses' => $this->list(data_get($truth, 'execution_lenses.required', [])),
            'blocked_if_missing' => $this->list(data_get($truth, 'execution_lenses.blocked_if_missing', [])),
            'tests' => $this->testsFromTruth($truth),
            'acceptance' => $this->list(data_get($truth, 'acceptance_universe.must_work', [])),
            'scope_guard' => [
                'allowed_files' => $this->list(data_get($assisted, 'execution_contract.allowed_files', [])),
                'forbidden_files' => $this->list(data_get($assisted, 'execution_contract.forbidden_files', [])),
            ],
            'repair_policy' => [
                'failure_capsule_required' => true,
                'repair_loop_required' => true,
                'outcome_memory_required' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $assisted
     */
    private function status(array $truth, array $assisted): string
    {
        if (($truth['status'] ?? null) !== 'ready') {
            return 'needs_product_truth';
        }
        if (($assisted['status'] ?? null) !== 'ready_for_assisted_execution') {
            return 'needs_context';
        }

        return 'ready_for_delivery';
    }

    /**
     * @param  array<string,mixed>  $truth
     */
    private function riskBand(array $truth): string
    {
        $required = $this->list(data_get($truth, 'execution_lenses.required', []));

        return array_intersect($required, ['security_driven', 'add', 'performance_driven']) !== []
            ? 'high'
            : 'medium';
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<string>
     */
    private function testsFromTruth(array $truth): array
    {
        $tests = $this->list(data_get($truth, 'proof_plan.tests', []));
        if ($tests === []) {
            return ['focused_tests'];
        }

        return array_map(
            static fn (string $test): string => match ($test) {
                'contract_tests' => 'contract tests',
                'security_regression_tests' => 'security regression tests',
                default => $test,
            },
            $tests,
        );
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
