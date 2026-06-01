<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainRoutingGovernanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Domain Routing Governance decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-domain-routing-governance [--json]
 *
 * Read-only and deterministic. With the safe default it runs the doc's own
 * worked example "crie uma campanha para vender meu SaaS" -> primary
 * `marketing`, secondaries strategy/research, carrying the matrix note that
 * publishing/spend needs approval. It also shows the scope rule
 * (programming.frontend is a flow, not a domain) and a failing creation gate.
 *
 * @see docs/engineering-knowledge-base/domains/domain-routing-governance.md
 */
class AtlasDomainRoutingGovernanceCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-domain-routing-governance {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · domain routing governance decider (prompt->domain matrix, flow vs new domain, creation gate).';

    public function handle(AtlasDomainRoutingGovernanceService $service): int
    {
        try {
            // Doc example: "crie uma campanha para vender meu SaaS" -> marketing.
            $campaign = $service->route([
                'prompt' => 'crie uma campanha para vender meu SaaS',
                'prompt_or_objective_ref' => 'doc-example-campaign',
            ]);

            // Doc scope rule: programming.frontend is a flow/profile, not a domain.
            $scope = $service->classifyScope(['label' => 'programming.frontend']);

            // A creation gate with a missing/weak answer must refuse the domain.
            $gate = $service->evaluateCreationGate([
                'proposed_domain' => 'bug_bounty',
                'answers' => [
                    'existing_domain_tried_and_failed' => false,
                ],
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'campaign_route' => $campaign,
                'frontend_scope' => $scope,
                'weak_creation_gate' => $gate,
                'canonical_domains' => array_keys($service->canonicalDomains()),
                'cited_contracts' => $service->citedContracts(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_routing_governance_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
