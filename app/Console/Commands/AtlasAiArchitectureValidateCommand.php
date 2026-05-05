<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Capability\AtlasCapabilityRegistry;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Domain\AtlasDomainManifestValidator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestratorRegistry;
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
    ): int {
        $capabilityReport = $capabilities->complianceReport();
        $orchestratorReport = $orchestrators->complianceReport();
        $catalog = $profiles->catalog();
        $domainReport = $domains->validateCatalog($catalog);
        $domainCatalogReport = $domainCatalog->inspect();

        $payload = [
            'schema_version' => 1,
            'status' => $capabilityReport['valid'] && $orchestratorReport['ok'] && $domainReport['ok'] ? 'ok' : 'failed',
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
