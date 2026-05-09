<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use App\Services\Ai\Kernel\Decision\DecisionBudgets;
use App\Services\Ai\Kernel\Decision\DecisionProviderSelection;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\IntentClassification;
use App\Services\Ai\Kernel\Envelope\KernelOutput;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Envelope\RoutingState;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Pipeline\AtlasKernelPipeline;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairAttempt;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairDecisionStatus;
use App\Services\Ai\Kernel\Repair\RepairEvidencePayloadFormatter;
use App\Services\Ai\Kernel\Repair\RepairPayloadNormalizer;
use App\Services\Ai\Kernel\Repair\RepairPolicy;
use App\Services\Ai\Kernel\Repair\RepairReason;
use App\Services\Ai\Kernel\Repair\RepairRequest;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Repair\RepairResult;
use App\Services\Ai\Kernel\Repair\RepairStrategyResolver;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Kernel\Surface\SurfaceAttachmentKind;
use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Kernel\Surface\SurfaceDomainFlowHintKey;
use App\Services\Ai\Kernel\Surface\SurfaceHintKey;
use App\Services\Ai\Surface\Adapters\BaseSurfaceAdapter;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class KernelContractComplianceTest extends TestCase
{
    public function test_envelope_slots_are_typed_kernel_contracts(): void
    {
        $envelope = new ReflectionClass(OperationEnvelope::class);

        $this->assertPropertyType($envelope, 'decision', DecisionReceipt::class);
        $this->assertPropertyType($envelope, 'output', KernelOutput::class);

        $routing = new ReflectionClass(RoutingState::class);
        $this->assertPropertyType($routing, 'intent', IntentClassification::class);
        $this->assertPropertyType($routing, 'profile', EffectiveProfile::class);
    }

    public function test_decision_receipt_uses_typed_nested_contracts(): void
    {
        $receipt = new ReflectionClass(DecisionReceipt::class);

        $this->assertPropertyType($receipt, 'providerSelection', DecisionProviderSelection::class);
        $this->assertPropertyType($receipt, 'budgets', DecisionBudgets::class);
        $this->assertPropertyType($receipt, 'repairPolicy', DecisionRepairPolicy::class);
        $this->assertPropertyType($receipt, 'dryRun', 'bool');
        $this->assertPropertyType($receipt, 'signedBy', 'string');
    }

    public function test_domain_orchestrator_sdk_exposes_execution_methods(): void
    {
        $contract = new ReflectionClass(AtlasDomainOrchestrator::class);

        foreach (['plan', 'execute', 'repair', 'summarize'] as $method) {
            $this->assertTrue($contract->hasMethod($method), "AtlasDomainOrchestrator must declare {$method}().");
        }
    }

    public function test_surface_and_provider_kernel_contracts_exist(): void
    {
        $surface = new ReflectionClass(SurfaceAdapter::class);
        $provider = new ReflectionClass(ProviderDriver::class);

        foreach (['surfaceId', 'supportedCapabilities', 'normalizeInput', 'renderOutput', 'complianceReport'] as $method) {
            $this->assertTrue($surface->hasMethod($method), "SurfaceAdapter must declare {$method}().");
        }

        foreach (['providerId', 'supportedModels', 'prepareRequest', 'execute', 'identityFragment'] as $method) {
            $this->assertTrue($provider->hasMethod($method), "ProviderDriver must declare {$method}().");
        }
    }

    public function test_surface_capability_hint_and_attachment_vocabularies_are_closed(): void
    {
        $this->assertSame([
            SurfaceAttachmentKind::ATTACHMENT,
            SurfaceAttachmentKind::AUDIO,
            SurfaceAttachmentKind::FILE,
            SurfaceAttachmentKind::IMAGE,
        ], SurfaceAttachmentKind::all());
        $this->assertTrue(SurfaceAttachmentKind::isKnown(SurfaceAttachmentKind::ATTACHMENT));
        $this->assertTrue(SurfaceAttachmentKind::isKnown(SurfaceAttachmentKind::AUDIO));
        $this->assertTrue(SurfaceAttachmentKind::isKnown(SurfaceAttachmentKind::FILE));
        $this->assertTrue(SurfaceAttachmentKind::isKnown(SurfaceAttachmentKind::IMAGE));

        $this->assertGreaterThanOrEqual(10, count(SurfaceCapability::all()));
        $this->assertSame(SurfaceCapability::all(), array_values(array_unique(SurfaceCapability::all())));
        $this->assertTrue(SurfaceCapability::isKnown(SurfaceCapability::TEXT));
        $this->assertTrue(SurfaceCapability::isKnown(SurfaceCapability::DOMAIN_FLOW_SELECTION));
        $this->assertTrue(SurfaceCapability::isKnown(SurfaceCapability::MEMORY_RECALL));
        $this->assertTrue(SurfaceCapability::isKnown(SurfaceCapability::CONTEXT_COMPOSE));
        $this->assertTrue(SurfaceCapability::isKnown(SurfaceCapability::TOOLS_RUNTIME));

        $this->assertGreaterThanOrEqual(20, count(SurfaceHintKey::all()));
        $this->assertSame(SurfaceHintKey::all(), array_values(array_unique(SurfaceHintKey::all())));
        $this->assertTrue(SurfaceHintKey::isKnown(SurfaceHintKey::SURFACE_ID));
        $this->assertTrue(SurfaceHintKey::isKnown(SurfaceHintKey::SURFACE_CAPABILITIES));
        $this->assertTrue(SurfaceHintKey::isKnown(SurfaceHintKey::DOMAIN_CATALOG_SELECTION));

        $this->assertGreaterThanOrEqual(9, count(SurfaceDomainFlowHintKey::all()));
        $this->assertSame(SurfaceDomainFlowHintKey::all(), array_values(array_unique(SurfaceDomainFlowHintKey::all())));
        $this->assertTrue(SurfaceDomainFlowHintKey::isKnown(SurfaceDomainFlowHintKey::SURFACE_ID));
        $this->assertTrue(SurfaceDomainFlowHintKey::isKnown(SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID));
        $this->assertTrue(SurfaceDomainFlowHintKey::isKnown(SurfaceDomainFlowHintKey::TASK_FLOW_MAP));
    }

    public function test_formal_surface_adapters_implement_kernel_contract(): void
    {
        $adapters = app(SurfaceAdapterRegistry::class)->all();

        foreach ($adapters as $adapter) {
            $this->assertInstanceOf(SurfaceAdapter::class, $adapter);
            $this->assertNotSame('', $adapter->surfaceId());
            $this->assertNotEmpty($adapter->supportedCapabilities(), "{$adapter->surfaceId()} must declare capabilities.");
            foreach ($adapter->supportedCapabilities() as $capability) {
                $this->assertContains($capability, SurfaceCapability::all(), "{$adapter->surfaceId()} declares unknown capability [{$capability}].");
            }

            $report = $adapter->complianceReport();
            $this->assertTrue($report['ok'], "{$adapter->surfaceId()} compliance failed: ".implode("\n", $report['errors']));
        }
    }

    public function test_all_concrete_surface_adapters_are_registered(): void
    {
        $discoveredClasses = $this->concreteSurfaceAdapterClasses();
        $registeredClasses = array_map(
            fn (SurfaceAdapter $adapter): string => $adapter::class,
            app(SurfaceAdapterRegistry::class)->all(),
        );

        sort($discoveredClasses);
        sort($registeredClasses);

        $this->assertSame($discoveredClasses, $registeredClasses);
    }

    public function test_surface_registry_is_compliant(): void
    {
        $surfaceReport = app(SurfaceAdapterRegistry::class)->complianceReport();

        $this->assertTrue($surfaceReport['ok'], implode("\n", $surfaceReport['errors']));
        $this->assertGreaterThanOrEqual(8, $surfaceReport['count']);
        $this->assertContains('atlas_cli_dev', $surfaceReport['surfaces']);
        $this->assertContains('atlas_worker', $surfaceReport['surfaces']);
        $this->assertContains('atlas_mcp_readonly', $surfaceReport['surfaces']);
        $this->assertContains('atlas_vault', $surfaceReport['surfaces']);
    }

    public function test_surface_layer_cannot_bypass_kernel_with_provider_calls(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap1_surface_provider_bypass']['violations'];

        $this->assertSame([], $violations, "Surface layer must only normalize/render kernel IO, never call providers directly:\n".implode("\n", $violations));
    }

    public function test_surface_layer_cannot_build_context_or_memory_directly(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap2_surface_context_bypass']['violations'];

        $this->assertSame([], $violations, "Surface layer must not build context packs or memory projections directly:\n".implode("\n", $violations));
    }

    public function test_provider_driver_layer_cannot_bypass_identity_and_validator_contracts(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap12_provider_driver_identity_bypass']['violations'];

        $this->assertSame([], $violations, "ProviderDriver wrappers must inject identity and validate prepared requests before any real execution path:\n".implode("\n", $violations));
    }

    public function test_gateway_must_persist_decision_receipt_across_trace_and_jobs(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap6_decision_receipt_propagation']['violations'];

        $this->assertSame([], $violations, "AiGatewayService must carry the DecisionReceipt through trace metadata, primary jobs, council jobs and scout jobs:\n".implode("\n", $violations));
    }

    public function test_worker_must_block_invalid_or_expired_decision_receipts_before_provider_execution(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap13_decision_receipt_runtime_guard']['violations'];

        $this->assertSame([], $violations, "AiWorker must reject invalid, dry-run or expired DecisionReceipts before provider lookup/execution:\n".implode("\n", $violations));
    }

    public function test_tool_runtime_must_block_heavy_tiers_from_hot_path(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap14_tool_tier_hot_path']['violations'];

        $this->assertSame([], $violations, "Interactive hot paths must not run T2/T3 tools without an explicit non-hot-path contract:\n".implode("\n", $violations));
    }

    public function test_provider_context_must_filter_memory_through_privacy_policy(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap15_provider_memory_privacy']['violations'];

        $this->assertSame([], $violations, "Provider-facing memory projections must use canonical privacy gates and redacted fields:\n".implode("\n", $violations));
    }

    public function test_slo_observations_must_be_recordable_to_evidence_ledger(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap16_slo_observability']['violations'];

        $this->assertSame([], $violations, "Kernel SLO targets must be measurable and persisted as Evidence Ledger observations:\n".implode("\n", $violations));
    }

    public function test_kernel_pipeline_contract_exists_and_is_scaffold_safe(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline;

        $this->assertInstanceOf(AtlasKernelPipeline::class, $pipeline);
        $this->assertSame(KernelPipelineStage::orderedValues(), array_map(
            fn ($stage): string => $stage->stage->value,
            $pipeline->stages(),
        ));

        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap17_kernel_pipeline_contract']['violations'];

        $this->assertSame([], $violations, "Kernel pipeline must expose canonical stages and remain scaffold-safe without direct provider/runtime execution:\n".implode("\n", $violations));
    }

    public function test_failure_domains_are_closed_and_have_handlers(): void
    {
        $this->assertGreaterThanOrEqual(30, count(FailureDomain::cases()));

        $report = app(FailureHandlerRegistry::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['missing']));
    }

    public function test_repair_loop_kernel_contract_exists_and_is_scaffold_safe(): void
    {
        foreach ([RepairRequest::class, RepairPolicy::class, RepairAttempt::class, RepairDecision::class, RepairResult::class, RepairRequestFactory::class, RepairStrategyResolver::class, RepairEvidencePayloadFormatter::class, RepairPayloadNormalizer::class] as $contract) {
            $this->assertTrue(class_exists($contract), "{$contract} must exist as a typed repair contract.");
        }

        $orchestrator = new ReflectionClass(AtlasRepairOrchestrator::class);

        foreach (['plan', 'attempt', 'strategyFor', 'complianceReport'] as $method) {
            $this->assertTrue($orchestrator->hasMethod($method), "AtlasRepairOrchestrator must declare {$method}().");
        }

        $factory = new ReflectionClass(RepairRequestFactory::class);
        foreach (['fromKernelContext', 'fromPayload', 'defaultPolicy'] as $method) {
            $this->assertTrue($factory->hasMethod($method), "RepairRequestFactory must declare {$method}().");
        }

        $resolver = new ReflectionClass(RepairStrategyResolver::class);
        foreach (['strategyFor', 'requiresHumanReview', 'complianceReport'] as $method) {
            $this->assertTrue($resolver->hasMethod($method), "RepairStrategyResolver must declare {$method}().");
        }

        $formatter = new ReflectionClass(RepairEvidencePayloadFormatter::class);
        foreach (['decisionPayload', 'resultPayload', 'stableHash'] as $method) {
            $this->assertTrue($formatter->hasMethod($method), "RepairEvidencePayloadFormatter must declare {$method}().");
        }

        $decisions = array_map(
            fn (RepairDecisionStatus $status): string => $status->value,
            RepairDecisionStatus::cases(),
        );

        $this->assertSame([
            'repair_allowed',
            'repair_blocked',
            'repair_exhausted',
            'needs_human_review',
        ], $decisions);

        $report = app(AtlasRepairOrchestrator::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertFalse($report['execution_enabled'], 'Repair contract foundation must not execute production repairs yet.');
        $this->assertSame(RepairReason::values(), $report['reasons']);
        $this->assertContains(FailureDomain::ComplianceViolation->value, $report['human_review_domains']);
    }

    public function test_repair_loop_cli_api_and_contract_are_static_scan_safe(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = $report['ap18_repair_loop_contract']['violations'];

        $this->assertSame([], $violations, "Repair Loop must expose only scaffold-safe CLI/API contracts, and native worker repairs must pass through the kernel contract before enqueuing follow-up repair jobs:\n".implode("\n", $violations));
    }

    public function test_kernel_slo_targets_are_quantified(): void
    {
        $report = app(KernelSloTargets::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(14, $report['count']);
    }

    private function assertPropertyType(ReflectionClass $class, string $property, string $expected): void
    {
        $type = $class->getProperty($property)->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame($expected, $type->getName(), "{$class->getName()}::\${$property} must be {$expected}");
    }

    /**
     * @return array<int,class-string<SurfaceAdapter>>
     */
    private function concreteSurfaceAdapterClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Services/Ai/Surface/Adapters/*SurfaceAdapter.php')) ?: [] as $path) {
            $class = 'App\\Services\\Ai\\Surface\\Adapters\\'.basename($path, '.php');
            if ($class === BaseSurfaceAdapter::class) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $this->assertTrue($reflection->implementsInterface(SurfaceAdapter::class), "{$class} must implement SurfaceAdapter.");

            $classes[] = $class;
        }

        return $classes;
    }
}
