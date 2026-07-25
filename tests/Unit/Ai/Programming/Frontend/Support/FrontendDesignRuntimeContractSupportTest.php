<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Frontend\Support;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemInventoryService;
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
}
