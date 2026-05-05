<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Decision\DecisionBudgets;
use App\Services\Ai\Kernel\Decision\DecisionProviderSelection;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\IntentClassification;
use App\Services\Ai\Kernel\Envelope\KernelOutput;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
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

        $routing = new ReflectionClass(\App\Services\Ai\Kernel\Envelope\RoutingState::class);
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

    public function test_failure_domains_are_closed_and_have_handlers(): void
    {
        $this->assertGreaterThanOrEqual(30, count(FailureDomain::cases()));

        $report = app(FailureHandlerRegistry::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['missing']));
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
}
