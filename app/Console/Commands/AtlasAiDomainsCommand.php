<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use Illuminate\Console\Command;

class AtlasAiDomainsCommand extends Command
{
    protected $signature = 'atlas:ai:domains
        {--domain= : Filter by domain id}
        {--flow= : Filter by flow id}
        {--maturity= : Filter orchestrators by maturity: implemented, scaffold, planned}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect Atlas AI domain, flow, and orchestrator contracts.';

    public function handle(AtlasAiDomainCatalogService $catalog): int
    {
        $payload = $catalog->inspect([
            'domain' => $this->option('domain'),
            'flow' => $this->option('flow'),
            'maturity' => $this->option('maturity'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Domains</>', $payload['status']);
        $this->components->twoColumnDetail('Catalog source', $payload['source']);
        $this->components->twoColumnDetail('Domains', (string) $payload['summary']['domains']);
        $this->components->twoColumnDetail('Flows', (string) $payload['summary']['flows']);
        $this->components->twoColumnDetail('Orchestrators', $payload['summary']['orchestrators'].' ('.$payload['summary']['implemented_orchestrators'].' implemented)');

        if (collect($payload['domains'])->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Domain', 'Default flow', 'Orchestrator', 'Maturity', 'Runtime', 'Autonomy', 'Onboarding'],
                collect($payload['domains'])->map(fn (array $domain): array => [
                    $domain['id'],
                    $domain['default_flow'],
                    $domain['orchestrator'],
                    $domain['orchestrator_maturity'],
                    $domain['runtime_family'],
                    $domain['autonomy_default'],
                    data_get($domain, 'onboarding.status').' '.(string) data_get($domain, 'onboarding.completed_count').'/'.(string) data_get($domain, 'onboarding.total_count'),
                ])->all(),
            );
        }

        if (collect($payload['flows'])->isNotEmpty()) {
            $this->table(
                ['Flow', 'Domain', 'Runtime', 'Orchestrator', 'Maturity', 'Autonomy'],
                collect($payload['flows'])->map(fn (array $flow): array => [
                    $flow['id'],
                    $flow['domain_id'],
                    $flow['runtime'],
                    $flow['orchestrator'],
                    $flow['orchestrator_maturity'],
                    $flow['autonomy'],
                ])->all(),
            );
        }

        foreach ($payload['validation']['errors'] as $error) {
            $this->error($error);
        }

        foreach ($payload['validation']['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
