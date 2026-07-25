<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Frontend\Support;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemInventoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendGauntletService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductBlueprintService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRepoIntakeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendScenarioMatrixService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkOrderService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorldBestProofPlanService;
use App\Services\Ai\Programming\Frontend\Support\FrontendDesignRuntimeContractSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-unit lock for {@see Support}
 * (array/string projection only; no FS / app() / provider I/O).
 *
 * Explicit path proof: DesignRuntime host imports Support and no longer
 * declares the peeled private methods.
 */
final class FrontendDesignRuntimeContractSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Programming/Frontend/Support/FrontendDesignRuntimeContractSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeService.php';

    /** @var list<string> */
    private const PEELED = [
        'signals',
        'outputTypes',
        'capabilities',
        'requiredGates',
        'requiredEvidence',
        'competitiveScorecard',
        'blockers',
        'warnings',
        'designQualityRules',
        'variantStrategy',
        'designSystemInventoryContract',
        'liveIterationContract',
        'productBlueprintContract',
        'enterpriseBootstrapContract',
        'gauntletContract',
        'workOrderContract',
        'executionRunbookContract',
        'providerInstructionPacketContract',
        'scenarioMatrixContract',
        'evidenceKitContract',
        'privateBenchmarkProofPlanContract',
        'worldBestProofPlanContract',
        'repoIntakeContract',
        'executionGateContract',
        'runCertificationContract',
        'deliveryHandoffContract',
        'outcomeMemoryContract',
        'containsAny',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 6);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Programming\Frontend\Support\FrontendDesignRuntimeContractSupport;',
            $hostSrc,
            'Host must import FrontendDesignRuntimeContractSupport',
        );

        foreach ([
            'signals',
            'outputTypes',
            'capabilities',
            'requiredGates',
            'requiredEvidence',
            'competitiveScorecard',
            'blockers',
            'warnings',
            'designQualityRules',
            'variantStrategy',
            'designSystemInventoryContract',
            'liveIterationContract',
            'productBlueprintContract',
            'enterpriseBootstrapContract',
            'gauntletContract',
            'workOrderContract',
            'executionRunbookContract',
            'providerInstructionPacketContract',
            'scenarioMatrixContract',
            'evidenceKitContract',
            'privateBenchmarkProofPlanContract',
            'worldBestProofPlanContract',
            'repoIntakeContract',
            'executionGateContract',
            'runCertificationContract',
            'deliveryHandoffContract',
            'outcomeMemoryContract',
        ] as $method) {
            $this->assertStringContainsString(
                'FrontendDesignRuntimeContractSupport::'.$method,
                $hostSrc,
                "Host must call Support::{$method}",
            );
        }

        foreach (self::PEELED as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function signals_and_output_types_are_deterministic_for_enterprise_live_brief(): void
    {
        $haystack = 'criar frontend saas multiempresa com live variants design system assets de marca e performance';
        $signals = Support::signals($haystack, ['benchmark_run' => true]);

        $this->assertTrue($signals['ui_change']);
        $this->assertTrue($signals['enterprise_multi_company']);
        $this->assertTrue($signals['live_iteration_requested']);
        $this->assertTrue($signals['asset_heavy']);
        $this->assertTrue($signals['broad_visual_change']);
        $this->assertTrue($signals['performance_sensitive']);
        $this->assertTrue($signals['accessibility_sensitive']);
        $this->assertTrue($signals['production_code']);

        $types = Support::outputTypes($signals);
        $this->assertContains('production_ui_patch', $types);
        $this->assertContains('clickable_prototype', $types);
        $this->assertContains('live_visual_iteration', $types);
    }

    #[Test]
    public function capabilities_gates_and_evidence_expand_for_live_and_enterprise(): void
    {
        $signals = Support::signals(
            'multiempresa redesign live browser design system logo performance',
            [],
        );
        $types = Support::outputTypes($signals);
        $capabilities = Support::capabilities($signals, $types);
        $gates = Support::requiredGates($signals, $types);
        $evidence = Support::requiredEvidence($signals, $types);

        $this->assertContains('multi_company_design_system_adaptation', $capabilities);
        $this->assertContains('live_css_preview_relay', $capabilities);
        $this->assertContains('senior_design_review', $gates);
        $this->assertContains('source_patch_boundary_check', $gates);
        $this->assertContains('live_iteration_event_journal', $evidence);
        $this->assertContains('frontend_asset_pack', $evidence);
    }

    #[Test]
    public function blockers_and_warnings_respect_option_flags(): void
    {
        $assetSignals = Support::signals('logo brand hero image', []);
        $blockers = Support::blockers($assetSignals, ['asset_provenance' => false]);
        $this->assertSame('asset_provenance_missing', $blockers[0]['id'] ?? null);

        $acceptance = Support::blockers(['asset_heavy' => false], ['acceptance_criteria' => false]);
        $this->assertSame('acceptance_criteria_missing', $acceptance[0]['id'] ?? null);

        $enterprise = Support::signals('multiempresa clientes white label', []);
        $warnings = Support::warnings($enterprise, ['benchmark_run' => false]);
        $this->assertSame('benchmark_not_run', $warnings[0]['id'] ?? null);

        $live = Support::signals('live browser accept discard', []);
        $liveWarnings = Support::warnings($live, ['benchmark_run' => true]);
        $this->assertSame('live_runtime_contract_only', $liveWarnings[0]['id'] ?? null);
    }

    #[Test]
    public function scorecard_rules_variant_inventory_and_live_contracts_are_pure(): void
    {
        $signals = [
            'enterprise_multi_company' => true,
            'broad_visual_change' => true,
            'live_iteration_requested' => true,
        ];
        $types = ['production_ui_patch', 'live_visual_iteration'];
        $capabilities = Support::capabilities($signals, $types);
        $gates = Support::requiredGates($signals, $types);
        $evidence = Support::requiredEvidence($signals, $types);
        $scorecard = Support::competitiveScorecard($capabilities, $gates, $evidence);

        $this->assertSame('atlas.frontend.competitive_scorecard.v1', $scorecard['schema_version']);
        $this->assertSame(count($capabilities), $scorecard['capability_count']);
        $this->assertStringContainsString('world-best', (string) data_get($scorecard, 'benchmarks.pbakaus_impeccable.remaining_gap'));

        $rules = Support::designQualityRules();
        $this->assertContains('no_text_overlap', array_column($rules, 'id'));

        $variant = Support::variantStrategy($signals);
        $this->assertSame(3, $variant['default_variant_count']);
        $this->assertSame('atlas.frontend.variant_strategy.v1', $variant['schema_version']);

        $inventory = Support::designSystemInventoryContract($signals);
        $this->assertSame(AtlasFrontendDesignSystemInventoryService::SCHEMA_VERSION, $inventory['schema_version']);
        $this->assertSame('required', $inventory['status']);

        $live = Support::liveIterationContract($signals);
        $this->assertSame('required', $live['status']);
        $this->assertSame('source_patch_only_with_boundary_and_recovery', $live['mutation_policy']);
        $this->assertSame('AtlasFrontendBrowserBridgeService', $live['browser_bridge_runtime']);
    }

    #[Test]
    public function contains_any_is_ascii_lower_needle_match(): void
    {
        $this->assertTrue(Support::containsAny('frontend layout screen', ['ui', 'layout']));
        $this->assertFalse(Support::containsAny('backend only', ['frontend', 'ui']));
    }

    #[Test]
    public function pure_static_sub_contracts_project_without_app_or_fs(): void
    {
        $blueprint = Support::productBlueprintContract();
        $this->assertSame(AtlasFrontendProductBlueprintService::SCHEMA_VERSION, $blueprint['schema_version']);
        $this->assertSame('required_for_premium_or_new_product_frontend', $blueprint['status']);
        $this->assertContains('ux_success_model', $blueprint['covers']);
        $this->assertTrue($blueprint['claim_policy']['premium_frontend_work_requires_blueprint']);
        $this->assertFalse($blueprint['claim_policy']['world_best_claim_allowed']);

        $gauntlet = Support::gauntletContract();
        $this->assertSame(AtlasFrontendGauntletService::SCHEMA_VERSION, $gauntlet['schema_version']);
        $this->assertSame('recommended_entrypoint_for_local_company_repos', $gauntlet['status']);
        $this->assertContains('company_design_dossier', $gauntlet['composes']);
        $this->assertTrue($gauntlet['claim_policy']['premium_frontend_claim_requires_ready_gauntlet']);

        $workOrder = Support::workOrderContract();
        $this->assertSame(AtlasFrontendWorkOrderService::SCHEMA_VERSION, $workOrder['schema_version']);
        $this->assertContains('visual_quality_verification', $workOrder['packets']);
        $this->assertTrue($workOrder['claim_policy']['provider_dispatch_requires_ready_work_order']);

        $runbook = Support::executionRunbookContract();
        $this->assertSame(AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, $runbook['schema_version']);
        $this->assertContains('customer_safe_handoff', $runbook['covers']);
        $this->assertTrue($runbook['claim_policy']['runbook_is_not_execution_evidence']);

        $packet = Support::providerInstructionPacketContract();
        $this->assertSame(AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertContains('forbidden_provider_behaviors', $packet['binds']);

        $scenarios = Support::scenarioMatrixContract();
        $this->assertSame(AtlasFrontendScenarioMatrixService::SCHEMA_VERSION, $scenarios['schema_version']);
        $this->assertContains('states', $scenarios['covers']);
        $this->assertTrue($scenarios['claim_policy']['visual_done_requires_scenario_matrix_evidence']);

        $kit = Support::evidenceKitContract();
        $this->assertSame(AtlasFrontendEvidenceKitService::SCHEMA_VERSION, $kit['schema_version']);
        $this->assertContains('run_certification_command', $kit['prepares']);

        $bootstrap = Support::enterpriseBootstrapContract();
        $this->assertSame(AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION, $bootstrap['schema_version']);
        $this->assertContains('new_saas_or_product_creation', $bootstrap['company_modes']);

        $intake = Support::repoIntakeContract();
        $this->assertSame(AtlasFrontendRepoIntakeService::SCHEMA_VERSION, $intake['schema_version']);
        $this->assertContains('framework_adapter', $intake['covers']);

        $gate = Support::executionGateContract();
        $this->assertSame(AtlasFrontendExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertTrue($gate['claim_policy']['provider_dispatch_requires_matching_task_spec_hash']);
        $this->assertContains('acceptance_criteria', $gate['blocks_provider_dispatch_when_missing']);

        $runCert = Support::runCertificationContract();
        $this->assertSame(AtlasFrontendRunCertificationService::SCHEMA_VERSION, $runCert['schema_version']);
        $this->assertContains('outcome_memory_record', $runCert['required_evidence']);
        $this->assertTrue($runCert['claim_policy']['frontend_completion_claim_requires_run_certification']);

        $handoff = Support::deliveryHandoffContract();
        $this->assertSame(AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION, $handoff['schema_version']);
        $this->assertContains('publication_attestation', $handoff['required_evidence']);
        $this->assertTrue($handoff['claim_policy']['local_publication_report_is_not_public_distribution']);

        $outcome = Support::outcomeMemoryContract();
        $this->assertSame(AtlasFrontendOutcomeMemoryService::SCHEMA_VERSION, $outcome['schema_version']);
        $this->assertContains('doctrine_effectiveness', $outcome['records']);
        $this->assertTrue($outcome['claim_policy']['frontend_learning_requires_outcome_record']);

        $private = Support::privateBenchmarkProofPlanContract();
        $this->assertSame(AtlasFrontendPrivateBenchmarkProofPlanService::SCHEMA_VERSION, $private['schema_version']);
        $this->assertTrue($private['claim_policy']['private_benchmark_for_internal_improvement_only']);
        $this->assertFalse($private['claim_policy']['world_best_claim_allowed']);

        $world = Support::worldBestProofPlanContract();
        $this->assertSame(AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION, $world['schema_version']);
        $this->assertSame('private_benchmark_proof_plan_contract', $world['canonical_replacement']);
        $this->assertContains('external_rival_replay', $world['required_proof_streams']);
    }

    #[Test]
    public function pure_support_sub_contract_source_has_no_app_or_file_io(): void
    {
        $root = dirname(__DIR__, 6);
        $src = (string) file_get_contents($root.'/'.self::SUPPORT_PATH);

        foreach ([
            'productBlueprintContract',
            'enterpriseBootstrapContract',
            'gauntletContract',
            'workOrderContract',
            'executionRunbookContract',
            'providerInstructionPacketContract',
            'scenarioMatrixContract',
            'evidenceKitContract',
            'privateBenchmarkProofPlanContract',
            'worldBestProofPlanContract',
            'repoIntakeContract',
            'executionGateContract',
            'runCertificationContract',
            'deliveryHandoffContract',
            'outcomeMemoryContract',
        ] as $method) {
            $this->assertMatchesRegularExpression(
                '/public static function '.$method.'\(/',
                $src,
                "Support must declare pure static {$method}",
            );
        }

        // Runtime DI/FS only — docblocks may mention "app()" as a purity constraint.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\w\'"\/])app\s*\(\s*[\\\\\'"A-Za-z_]/',
            $src,
            'Support must not call app() at runtime',
        );
        $this->assertStringNotContainsString('File::', $src, 'Support must not use File facade');
        $this->assertStringNotContainsString('base_path(', $src, 'Support must not touch FS via base_path');
        $this->assertStringNotContainsString('storage_path(', $src, 'Support must not touch storage_path');
    }
}
