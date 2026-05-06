<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Surface\DomainCatalogSurfaceSelectionService;
use Illuminate\Console\Command;

class AtlasAiDomainsCommand extends Command
{
    protected $signature = 'atlas:ai:domains
        {--domain= : Filter by domain id}
        {--flow= : Filter by flow id}
        {--maturity= : Filter orchestrators by maturity: implemented, scaffold, planned}
        {--onboarding-status= : Filter domains by onboarding status: ready, executable_incomplete, scaffold}
        {--select : Preview the canonical domain/flow selection for a surface UX route}
        {--surface=atlas_cli : Surface id used by --select, for example atlas_cli, atlas_app, atlas_api or atlas_mcp_readonly}
        {--mode=general : UX mode used by --select: general, operational or programming}
        {--task=direct : UX task used by --select: direct, plan, review, dev or debug}
        {--routing-domain= : Optional product/routing domain used by --select, for example blackink or atlas}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect Atlas AI domain, flow, and orchestrator contracts.';

    public function handle(AtlasAiDomainCatalogService $catalog, DomainCatalogSurfaceSelectionService $surfaceSelection): int
    {
        $payload = $catalog->inspect([
            'domain' => $this->option('domain'),
            'flow' => $this->option('flow'),
            'maturity' => $this->option('maturity'),
            'onboarding_status' => $this->option('onboarding-status'),
        ]);

        if ((bool) $this->option('select')) {
            $payload['surface_selection'] = $surfaceSelection->select([
                'surface_id' => $this->option('surface'),
                'mode' => $this->option('mode'),
                'task' => $this->option('task'),
                'routing_domain' => $this->option('routing-domain'),
                'domain_id' => $this->option('domain'),
                'flow_id' => $this->option('flow'),
            ]);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Domains</>', $payload['status']);
        $this->components->twoColumnDetail('Catalog source', $payload['source']);
        $this->components->twoColumnDetail('Domains', (string) $payload['summary']['domains']);
        $this->components->twoColumnDetail('Flows', (string) $payload['summary']['flows']);
        $this->components->twoColumnDetail('Orchestrators', $payload['summary']['orchestrators'].' ('.$payload['summary']['implemented_orchestrators'].' implemented)');
        $this->components->twoColumnDetail(
            'Onboarding',
            'ready '.$payload['summary']['ready_domains']
                .' / scaffold '.$payload['summary']['scaffold_domains']
                .' / incomplete '.$payload['summary']['executable_incomplete_domains']
        );

        if (isset($payload['surface_selection']) && is_array($payload['surface_selection'])) {
            $selection = $payload['surface_selection'];
            $this->newLine();
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Surface Selection</>', (string) ($selection['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Surface', (string) ($selection['surface_id'] ?? '-'));
            $this->components->twoColumnDetail('Domain', (string) data_get($selection, 'domain.id', '-'));
            $this->components->twoColumnDetail('Flow', (string) data_get($selection, 'flow.id', '-'));
            $this->components->twoColumnDetail('Executor', (string) data_get($selection, 'flow.executor_preference', '-'));
            $this->components->twoColumnDetail('Safety', data_get($selection, 'safety.onboarding_status', '-').' / '.data_get($selection, 'safety.autonomy', '-'));
        }

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
