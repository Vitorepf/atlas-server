<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientGovernanceRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCapabilityContract;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCostQualityRouter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolIndependenceGate;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator entry point combining {@see AtlasExternalBrainProviderPoolCapabilityContract},
 * {@see AtlasExternalBrainProviderPoolCostQualityRouter} and {@see AtlasExternalBrainProviderPoolIndependenceGate}
 * into one provider-pool readiness and independence verdict — so optional accelerators
 * stay optional and any hidden steady-state dependency blocks production promotion.
 *
 * Also surfaces the local-client governance verdict via
 * {@see AtlasExternalBrainLocalClientGovernanceRunner} so the four dormant
 * local-client organs (fallback, recovery, cost, fragility) run on every
 * provider-independence report.
 *
 * Never mutates files, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { provider_pools:list<...>, router:{...AtlasExternalBrainProviderPoolCostQualityRouter::route facts...},
 *     providers:list<...AtlasExternalBrainProviderPoolIndependenceGate providers...> }
 * Missing/absent sections default to empty and simply produce a clean, unblocked report.
 */
final class AtlasExternalBrainProviderIndependenceCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:provider-independence
        {--input= : Path to a JSON file with provider_pools, router and providers sections}';

    /** @var string */
    protected $description = 'Read-only provider-pool capability + cost/quality routing + independence-gate + local-client-governance verdict: optional accelerators stay optional, hidden steady-state dependency blocks promotion.';

    public function handle(
        AtlasExternalBrainProviderPoolCapabilityContract $capabilityContract,
        AtlasExternalBrainProviderPoolCostQualityRouter $router,
        AtlasExternalBrainProviderPoolIndependenceGate $independenceGate,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $providerPools = is_array($decoded['provider_pools'] ?? null) ? $decoded['provider_pools'] : [];
        $routerFacts = is_array($decoded['router'] ?? null) ? $decoded['router'] : [];
        $providers = is_array($decoded['providers'] ?? null) ? $decoded['providers'] : [];
        $localClientFacts = is_array($decoded['local_client'] ?? null) ? $decoded['local_client'] : [];

        $capability = $capabilityContract->describe(['provider_pools' => $providerPools]);
        $routing = $router->route($routerFacts);
        $independence = $independenceGate->evaluate(['providers' => $providers]);
        $localClientGovernance = (new AtlasExternalBrainLocalClientGovernanceRunner)->run($localClientFacts);

        $readyForProduction = ! $independence['production_promotion_blocked'];

        $payload = [
            'status' => 'ok',
            'capability_matrix' => $capability['capability_matrix'],
            'provider_count' => $capability['provider_count'],
            'route_decision' => $routing['route_decision'],
            'fallback_route' => $routing['fallback_route'],
            'escalation_policy' => $routing['escalation_policy'],
            'autonomy_preserved' => $independence['autonomy_preserved'],
            'production_promotion_blocked' => $independence['production_promotion_blocked'],
            'required_for_steady_state_providers' => $independence['required_for_steady_state_providers'],
            'minimal_next_tasks_needed_to_restore_independence' => $independence['minimal_next_tasks_needed_to_restore_independence'],
            'local_client_governance' => $localClientGovernance,
            'ready_for_production' => $readyForProduction,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
