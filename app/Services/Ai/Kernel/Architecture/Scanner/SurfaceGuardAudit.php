<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class SurfaceGuardAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap1_surface_provider_bypass' => fn (): array => $this->primitives->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
                'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
                'App\\Services\\Ai\\Provider\\Drivers\\',
                'App\\Services\\Ai\\ClaudeCliProvider',
                'App\\Services\\Ai\\CodexCliProvider',
                'App\\Services\\Ai\\GeminiCliProvider',
                'App\\Services\\Ai\\AiGatewayService',
                'App\\Services\\Ai\\AiWorker',
                'ProviderDriverRegistry',
                'ClaudeCliProvider',
                'CodexCliProvider',
                'GeminiCliProvider',
                'provider->execute(',
                'prepareRequest(',
            ]),
            'ap2_surface_context_bypass' => fn (): array => $this->primitives->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
                'App\\Services\\Ai\\Context\\AiContextPackBuilder',
                'App\\Services\\Ai\\AtlasOpenBrainContextInjectionService',
                'App\\Services\\Ai\\Memory\\AtlasMemoryRegistryService',
                'App\\Services\\Ai\\EngineeringContextPackService',
                'App\\Services\\Engineering\\EngineeringContextPackService',
                'ContextPackBuilder',
                'OpenBrainContextInjection',
                'AtlasMemoryRegistry',
                'EngineeringContextPack',
                'new ContextPack',
                'context_pack',
                'contextPack',
                'context_refs',
                'contextRefs',
                'memory_refs',
                'memoryRefs',
            ]),
            'ap12_provider_driver_identity_bypass' => fn (): array => $this->primitives->scanPhpFilesForForbiddenTokens(
                app_path('Services/Ai/Provider/Drivers'),
                [
                    'new ClaudeCliProvider',
                    'new CodexCliProvider',
                    'new GeminiCliProvider',
                    'app(ClaudeCliProvider',
                    'app(CodexCliProvider',
                    'app(GeminiCliProvider',
                    'provider_real_execution_allowed\' => true',
                    'provider_real_execution_allowed" => true',
                ],
                [
                    app_path('Services/Ai/Provider/Drivers/ProviderDriverRegistry.php'),
                ],
            ),
            'ap14_tool_tier_hot_path' => fn (): array => $this->scanToolTierHotPathPolicy(),
            'ap24_surface_alias_canonicalization' => fn (): array => $this->scanSurfaceAliasCanonicalization(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanToolTierHotPathPolicy(): array
    {
        $gatewayPath = app_path('Services/Ai/AiGatewayService.php');
        $policyPath = app_path('Services/Tools/AtlasToolPolicyEngine.php');
        $violations = [];

        if (! File::exists($gatewayPath)) {
            $violations[] = "missing gateway [{$gatewayPath}]";
        }

        if (! File::exists($policyPath)) {
            $violations[] = "missing tool policy engine [{$policyPath}]";
        }

        $gateway = PeeledSource::read($gatewayPath);
        $policy = PeeledSource::read($policyPath);

        $gatewayChecks = [
            'gateway records requested tool execution tier' => 'requested_execution_tier',
            'gateway records contract max execution tier' => 'max_execution_tier',
            'gateway records hot path flag' => 'hot_path',
            'gateway blocks T2/T3 hot path requests' => 'execution_tier_hot_path_blocked',
            'gateway blocks tiers above contract' => 'execution_tier_above_contract',
            'gateway compares tier weights' => 'executionTierWeight(',
            'gateway records policy contract blocks to ledger' => "recordPolicyContractBlocked('programming.tools'",
        ];

        foreach ($gatewayChecks as $label => $token) {
            if (! str_contains($gateway, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $policyChecks = [
            'tool policy reads max execution tier' => 'max_execution_tier',
            'tool policy blocks tier above budget' => 'execution_tier_above_policy_budget',
            'tool policy compares execution tier weight' => 'tierWeight($executionTier) > $this->tierWeight($maxExecutionTier)',
        ];

        foreach ($policyChecks as $label => $token) {
            if (! str_contains($policy, $token)) {
                $violations[] = "app/Services/Tools/AtlasToolPolicyEngine.php: missing {$label} [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSurfaceAliasCanonicalization(): array
    {
        $registryPath = app_path('Services/Ai/Surface/SurfaceAdapterRegistry.php');
        $testPath = base_path('tests/Unit/Ai/Surface/SurfaceAdaptersTest.php');
        $validateTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');

        $registry = PeeledSource::read($registryPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $validateTest = File::exists($validateTestPath) ? File::get($validateTestPath) : '';

        $violations = [];

        foreach ([
            "'atlas_dev' => 'atlas_cli_dev'",
            "'atlas_forge' => 'atlas_cli_forge'",
            "'atlas_fix' => 'atlas_cli_dev'",
            "'atlas_continue' => 'atlas_cli_dev'",
            "'atlas_ask' => 'atlas_cli_chat'",
            "'atlas_chat' => 'atlas_cli_chat'",
            "'atlas_cli_fix' => 'atlas_cli_dev'",
            "'atlas_cli_continue' => 'atlas_cli_dev'",
            "'atlas_cli_ask' => 'atlas_cli_chat'",
            "'aliases' => self::SURFACE_ALIASES",
            'surface alias [{$alias}] points to unsupported surface',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Surface/SurfaceAdapterRegistry.php: human CLI aliases must canonicalize to existing surface adapters [{$token}]";
            }
        }

        foreach ([
            "\$this->assertSame('atlas_cli_chat', \$registry->canonicalSurfaceId('atlas_ask'))",
            "\$this->assertSame('atlas_cli_dev', \$registry->canonicalSurfaceId('atlas_fix'))",
            "\$this->assertSame('atlas_cli_dev', \$registry->canonicalSurfaceId('atlas_continue'))",
            "\$this->assertSame('atlas_cli_forge', \$registry->canonicalSurfaceId('atlas_forge'))",
            "\$this->assertSame('atlas_cli_chat', \$registry->get('atlas_ask')->surfaceId())",
            "\$this->assertSame('atlas_cli_dev', \$registry->get('atlas_cli_continue')->surfaceId())",
            "\$this->assertSame('atlas_cli_chat', \$report['aliases']['atlas_ask'])",
            "\$this->assertSame('atlas_cli_dev', \$report['aliases']['atlas_cli_continue'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Surface/SurfaceAdaptersTest.php: surface alias canonicalization must be covered by registry tests [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel.surface_adapters.aliases.atlas_ask')",
            "data_get(\$payload, 'kernel.surface_adapters.aliases.atlas_cli_continue')",
            "'kernel.static_scan.ap24_surface_alias_canonicalization.valid'",
            "'kernel.static_scan.ap24_surface_alias_canonicalization.violations'",
        ] as $token) {
            if (! str_contains($validateTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: architecture validate must publish AP24 and surface aliases [{$token}]";
            }
        }

        return $violations;
    }
}
