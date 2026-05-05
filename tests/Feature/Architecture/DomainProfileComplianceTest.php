<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Domain\AtlasDomainManifestValidator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestratorRegistry;
use Tests\TestCase;

class DomainProfileComplianceTest extends TestCase
{
    public function test_atlas_domain_catalog_is_compliant(): void
    {
        $catalog = app(AtlasDomainProfileRegistry::class)->catalog();
        $report = app(AtlasDomainManifestValidator::class)->validateCatalog($catalog);

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(8, $report['domains']);
        $this->assertGreaterThanOrEqual(8, $report['flows']);
    }

    public function test_marketing_and_self_improvement_are_first_class_domains(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $marketing = $registry->resolve('marketing.campaign');
        $selfImprovement = $registry->resolve('self_improvement.nightly_review');

        $this->assertSame('marketing', $marketing['domain_id']);
        $this->assertSame('marketing.campaign', $marketing['flow_id']);
        $this->assertSame('AtlasMarketingOrchestrator', data_get($marketing, 'domain_profile.orchestrator'));
        $this->assertSame('MarketingRuntime', data_get($marketing, 'flow_profile.runtime'));
        $this->assertNotEmpty(data_get($marketing, 'domain_profile.context_policy.sources'));
        $this->assertNotEmpty(data_get($marketing, 'flow_profile.gate_policy.required'));

        $this->assertSame('self_improvement', $selfImprovement['domain_id']);
        $this->assertSame('self_improvement.nightly_review', $selfImprovement['flow_id']);
        $this->assertSame('AtlasSelfImprovementOrchestrator', data_get($selfImprovement, 'domain_profile.orchestrator'));
        $this->assertSame('SelfImprovementRuntime', data_get($selfImprovement, 'flow_profile.runtime'));
        $this->assertTrue((bool) data_get($selfImprovement, 'flow_profile.background_allowed'));
    }

    public function test_marketing_declares_full_growth_domain_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'marketing.strategy' => 'MarketingStrategyRuntime',
            'marketing.research' => 'MarketingResearchRuntime',
            'marketing.positioning' => 'MarketingStrategyRuntime',
            'marketing.campaign' => 'MarketingRuntime',
            'marketing.creative' => 'MarketingCreativeRuntime',
            'marketing.copywriting' => 'MarketingCopyRuntime',
            'marketing.media_plan' => 'MarketingMediaRuntime',
            'marketing.landing_page' => 'MarketingAssetRuntime',
            'marketing.email' => 'MarketingAssetRuntime',
            'marketing.social' => 'MarketingAssetRuntime',
            'marketing.video_script' => 'MarketingCreativeRuntime',
            'marketing.ab_test' => 'MarketingExperimentRuntime',
            'marketing.analytics' => 'MarketingAnalyticsRuntime',
            'marketing.brand_review' => 'MarketingReviewRuntime',
            'marketing.forge' => 'MarketingForgeRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('marketing', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasMarketingOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.gate_policy.required'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.context_policy.sources'));
        }

        $this->assertSame('domain_forge_runtime', data_get($registry->resolve('marketing.forge'), 'flow_profile.execution_policy.executor_preference'));
    }

    public function test_programming_declares_specialized_domain_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'programming.dev' => 'ProviderExecution',
            'programming.repair' => 'ProviderExecution',
            'programming.review' => 'ProviderExecution',
            'programming.refactor' => 'ProviderExecution',
            'programming.qa' => 'EngineeringHarness',
            'programming.security' => 'EngineeringHarness',
            'programming.database' => 'EngineeringHarness',
            'programming.visual' => 'EngineeringHarness',
            'programming.forge' => 'EngineeringHarness',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('programming', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasProgrammingOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.execution_policy.executor_preference'));
        }

        $this->assertSame('dev_repair_executor', data_get($registry->resolve('programming.repair'), 'flow_profile.execution_policy.executor_preference'));
        $this->assertSame('read_only', data_get($registry->resolve('programming.review'), 'flow_profile.tool_policy.mode'));
        $this->assertSame('engineering_harness', data_get($registry->resolve('programming.security'), 'flow_profile.execution_policy.executor_preference'));
    }

    public function test_every_active_flow_has_executable_orchestrator_contract(): void
    {
        $catalog = app(AtlasDomainProfileRegistry::class)->catalog();
        $orchestrators = app(AtlasDomainOrchestratorRegistry::class);
        $report = $orchestrators->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(14, $report['orchestrators']);

        foreach ((array) ($catalog['flows'] ?? []) as $flow) {
            if (($flow['status'] ?? 'active') !== 'active') {
                continue;
            }

            $orchestratorId = (string) ($flow['orchestrator'] ?? '');
            $definition = $orchestrators->get($orchestratorId);

            $this->assertNotNull($definition, "Flow {$flow['id']} references unregistered orchestrator {$orchestratorId}");
            $this->assertTrue(class_exists((string) $definition['class']), "Flow {$flow['id']} orchestrator class is missing");
            $this->assertTrue(is_subclass_of((string) $definition['class'], AtlasDomainOrchestrator::class), "Flow {$flow['id']} orchestrator must implement the SDK contract");

            /** @var AtlasDomainOrchestrator $instance */
            $instance = app((string) $definition['class']);
            $this->assertContains((string) $flow['domain_id'], $instance->supportedDomains(), "Flow {$flow['id']} domain is not supported by {$orchestratorId}");
            $this->assertContains((string) $flow['id'], $instance->supportedFlows(), "Flow {$flow['id']} is not supported by {$orchestratorId}");
        }
    }

    public function test_validator_rejects_orphan_default_flow(): void
    {
        $catalog = [
            'domains' => [
                [
                    'id' => 'marketing',
                    'label' => 'Marketing',
                    'status' => 'active',
                    'default_flow' => 'marketing.missing',
                    'orchestrator' => 'AtlasMarketingOrchestrator',
                    'runtime_family' => 'marketing',
                    'autonomy_default' => 'medium',
                ],
            ],
            'flows' => [],
        ];

        $report = app(AtlasDomainManifestValidator::class)->validateCatalog($catalog);

        $this->assertFalse($report['ok']);
        $this->assertContains('domain marketing default_flow marketing.missing is not declared as an active flow', $report['errors']);
    }
}
