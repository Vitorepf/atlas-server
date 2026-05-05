<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use App\Services\Ai\Kernel\Capability\AtlasCapabilityRegistry;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Domain\AtlasDomainManifestValidator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestratorRegistry;
use App\Services\Ai\Kernel\Failure\FailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use Illuminate\Console\Command;

class AtlasAiArchitectureValidateCommand extends Command
{
    protected $signature = 'atlas:ai:architecture-validate
        {--json : Print machine-readable JSON}';

    protected $description = 'Validate Atlas AI executable architecture contracts for capabilities and domain profiles.';

    public function handle(
        AtlasCapabilityRegistry $capabilities,
        AtlasDomainProfileRegistry $profiles,
        AtlasDomainManifestValidator $domains,
        AtlasDomainOrchestratorRegistry $orchestrators,
        AtlasAiDomainCatalogService $domainCatalog,
        FailureClassifier $failureClassifier,
        FailureHandlerRegistry $failureHandlers,
        KernelSloTargets $sloTargets,
        SurfaceAdapterRegistry $surfaceAdapters,
        ProviderDriverRegistry $providerDrivers,
        KernelArchitectureStaticScanner $staticScanner,
    ): int {
        $capabilityReport = $capabilities->complianceReport();
        $orchestratorReport = $orchestrators->complianceReport();
        $catalog = $profiles->catalog();
        $domainReport = $domains->validateCatalog($catalog);
        $domainCatalogReport = $domainCatalog->inspect();
        $failureClassifierReport = $failureClassifier->complianceReport();
        $failureReport = $failureHandlers->complianceReport();
        $sloReport = $sloTargets->complianceReport();
        $surfaceReport = $surfaceAdapters->complianceReport();
        $providerReport = $providerDrivers->complianceReport();
        $staticScanReport = $staticScanner->complianceReport();
        $kernelContracts = [
            'surface_adapter' => interface_exists(SurfaceAdapter::class),
            'provider_driver' => interface_exists(ProviderDriver::class),
        ];
        $kernelValid = $failureClassifierReport['ok']
            && $failureReport['ok']
            && $sloReport['ok']
            && $surfaceReport['ok']
            && $providerReport['ok']
            && $staticScanReport['ok']
            && $kernelContracts['surface_adapter']
            && $kernelContracts['provider_driver'];

        $payload = [
            'schema_version' => 1,
            'status' => $capabilityReport['valid'] && $orchestratorReport['ok'] && $domainReport['ok'] && $kernelValid ? 'ok' : 'failed',
            'kernel' => [
                'valid' => $kernelValid,
                'surface_adapter_contract' => $kernelContracts['surface_adapter'],
                'provider_driver_contract' => $kernelContracts['provider_driver'],
                'failure_domains' => [
                    'valid' => $failureReport['ok'],
                    'count' => $failureReport['count'],
                    'missing_handlers' => $failureReport['missing'],
                ],
                'failure_classifier' => [
                    'valid' => $failureClassifierReport['ok'],
                    'rule_count' => $failureClassifierReport['rule_count'],
                    'status_code_count' => $failureClassifierReport['status_code_count'],
                    'missing_domains' => $failureClassifierReport['missing_domains'],
                    'duplicate_signals' => $failureClassifierReport['duplicate_signals'],
                ],
                'slo_targets' => [
                    'valid' => $sloReport['ok'],
                    'count' => $sloReport['count'],
                    'schema_version' => $sloReport['schema_version'],
                    'stages' => $sloReport['stages'],
                    'errors' => $sloReport['errors'],
                ],
                'surface_adapters' => [
                    'valid' => $surfaceReport['ok'],
                    'count' => $surfaceReport['count'],
                    'surfaces' => $surfaceReport['surfaces'],
                    'errors' => $surfaceReport['errors'],
                ],
                'provider_drivers' => [
                    'valid' => $providerReport['ok'],
                    'count' => $providerReport['count'],
                    'providers' => $providerReport['providers'],
                    'errors' => $providerReport['errors'],
                    'warnings' => $providerReport['warnings'],
                ],
                'static_scan' => [
                    'valid' => $staticScanReport['ok'],
                    'ap1_surface_provider_bypass' => $staticScanReport['ap1_surface_provider_bypass'],
                    'ap2_surface_context_bypass' => $staticScanReport['ap2_surface_context_bypass'],
                    'ap6_decision_receipt_propagation' => $staticScanReport['ap6_decision_receipt_propagation'],
                    'ap13_decision_receipt_runtime_guard' => $staticScanReport['ap13_decision_receipt_runtime_guard'],
                    'ap12_provider_driver_identity_bypass' => $staticScanReport['ap12_provider_driver_identity_bypass'],
                    'ap14_tool_tier_hot_path' => $staticScanReport['ap14_tool_tier_hot_path'],
                    'ap15_provider_memory_privacy' => $staticScanReport['ap15_provider_memory_privacy'],
                    'ap16_slo_observability' => $staticScanReport['ap16_slo_observability'],
                    'ap17_kernel_pipeline_contract' => $staticScanReport['ap17_kernel_pipeline_contract'],
                    'ap18_repair_loop_contract' => $staticScanReport['ap18_repair_loop_contract'],
                ],
            ],
            'capabilities' => [
                'valid' => $capabilityReport['valid'],
                'count' => $capabilities->all()->count(),
                'surface_count' => count($capabilities->surfaces()),
                'errors' => $capabilityReport['errors'],
                'warnings' => $capabilityReport['warnings'],
            ],
            'orchestrators' => [
                'valid' => $orchestratorReport['ok'],
                'count' => $orchestratorReport['orchestrators'],
                'errors' => $orchestratorReport['errors'],
                'warnings' => $orchestratorReport['warnings'],
            ],
            'domains' => [
                'valid' => $domainReport['ok'],
                'source' => (string) ($catalog['source'] ?? 'unknown'),
                'domain_count' => $domainReport['domains'],
                'flow_count' => $domainReport['flows'],
                'errors' => $domainReport['errors'],
                'warnings' => $domainReport['warnings'],
            ],
            'onboarding' => [
                'ready_domains' => (int) data_get($domainCatalogReport, 'summary.ready_domains', 0),
                'executable_incomplete_domains' => (int) data_get($domainCatalogReport, 'summary.executable_incomplete_domains', 0),
                'domain_count' => (int) data_get($domainCatalogReport, 'summary.domains', 0),
            ],
            'validated_at' => now()->toJSON(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Architecture</>', $payload['status']);
        $this->components->twoColumnDetail('Capabilities', $payload['capabilities']['count'].' across '.$payload['capabilities']['surface_count'].' surfaces');
        $this->components->twoColumnDetail('Orchestrators', (string) $payload['orchestrators']['count']);
        $this->components->twoColumnDetail('Domains', $payload['domains']['domain_count'].' domains / '.$payload['domains']['flow_count'].' flows');
        $this->components->twoColumnDetail('Kernel', $payload['kernel']['failure_domains']['count'].' failure domains / '.$payload['kernel']['failure_classifier']['rule_count'].' classifier rules / '.$payload['kernel']['slo_targets']['count'].' SLO targets');
        $this->components->twoColumnDetail('Surface adapters', (string) $payload['kernel']['surface_adapters']['count']);
        $this->components->twoColumnDetail('Provider drivers', (string) $payload['kernel']['provider_drivers']['count']);
        $this->components->twoColumnDetail('Onboarding', $payload['onboarding']['ready_domains'].' ready / '.$payload['onboarding']['executable_incomplete_domains'].' executable incomplete');
        $this->components->twoColumnDetail('Catalog source', $payload['domains']['source']);

        foreach ($payload['capabilities']['errors'] as $error) {
            $this->error('[capability] '.$error);
        }

        foreach ($payload['domains']['errors'] as $error) {
            $this->error('[domain] '.$error);
        }

        foreach ($payload['orchestrators']['errors'] as $error) {
            $this->error('[orchestrator] '.$error);
        }

        foreach ($payload['kernel']['failure_domains']['missing_handlers'] as $error) {
            $this->error('[kernel.failure] missing handler for '.$error);
        }

        foreach ($payload['kernel']['failure_classifier']['missing_domains'] as $error) {
            $this->error('[kernel.failure_classifier] missing classifier rule for '.$error);
        }

        foreach ($payload['kernel']['failure_classifier']['duplicate_signals'] as $error) {
            $this->error('[kernel.failure_classifier] duplicate classifier signal '.$error);
        }

        foreach ($payload['kernel']['slo_targets']['errors'] as $error) {
            $this->error('[kernel.slo] '.$error);
        }

        foreach ($payload['kernel']['surface_adapters']['errors'] as $error) {
            $this->error('[kernel.surface] '.$error);
        }

        foreach ($payload['kernel']['provider_drivers']['errors'] as $error) {
            $this->error('[kernel.provider] '.$error);
        }

        foreach ($payload['kernel']['provider_drivers']['warnings'] as $warning) {
            $this->warn('[kernel.provider] '.$warning);
        }

        foreach ($payload['kernel']['static_scan']['ap1_surface_provider_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap1] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap2_surface_context_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap2] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap6_decision_receipt_propagation']['violations'] as $violation) {
            $this->error('[kernel.static.ap6] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap13_decision_receipt_runtime_guard']['violations'] as $violation) {
            $this->error('[kernel.static.ap13] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap12_provider_driver_identity_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap12] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap14_tool_tier_hot_path']['violations'] as $violation) {
            $this->error('[kernel.static.ap14] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap15_provider_memory_privacy']['violations'] as $violation) {
            $this->error('[kernel.static.ap15] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap16_slo_observability']['violations'] as $violation) {
            $this->error('[kernel.static.ap16] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap17_kernel_pipeline_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap17] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap18_repair_loop_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap18] '.$violation);
        }

        foreach ($payload['capabilities']['warnings'] as $warning) {
            $this->warn('[capability] '.$warning);
        }

        foreach ($payload['domains']['warnings'] as $warning) {
            $this->warn('[domain] '.$warning);
        }

        foreach ($payload['orchestrators']['warnings'] as $warning) {
            $this->warn('[orchestrator] '.$warning);
        }

        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
