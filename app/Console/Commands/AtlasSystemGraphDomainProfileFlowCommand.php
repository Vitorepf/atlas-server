<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphDomainProfileFlowService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas System Graph · Domain Profile Flow decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-system-graph-domain-profile-flow [--json]
 *
 * Read-only and deterministic. With the safe default it selects the doc's
 * worked example (domain programming + flow forge, emitting that flow's gates),
 * shows that a foreign flow is rejected with NO gates (doc "Riscos": fluxo
 * errado aplica gates errados), and shows the declare-the-domain guard rejecting
 * an implementation intent that did not declare a domain (doc "Regras para IA").
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-profile-flow.md
 */
class AtlasSystemGraphDomainProfileFlowCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-system-graph-domain-profile-flow {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas · Domain Profile Flow decider (selects domain + vertical profile + executive flow and emits the flow gates).';

    public function handle(AtlasSystemGraphDomainProfileFlowService $service): int
    {
        try {
            // Doc "Exemplos": Atlas Code uses domain programming + forge flow.
            $programmingForge = $service->select([
                'business_context' => 'atlas-code',
                'intent' => 'implement',
                'domain' => 'programming',
                'flow' => 'forge',
                'selection_ref' => 'doc-example-programming-forge',
            ]);

            // Doc "Riscos": a foreign flow must be rejected with no gates.
            $foreignFlow = $service->select([
                'business_context' => 'atlas-code',
                'intent' => 'implement',
                'domain' => 'programming',
                'flow' => 'campaign', // belongs to marketing, not programming
                'selection_ref' => 'doc-risk-foreign-flow',
            ]);

            // Doc "Regras para IA": implementation intent with no declared domain.
            $undeclared = $service->select([
                'business_context' => 'atlas-code',
                'intent' => 'implement',
                'selection_ref' => 'doc-rule-declare-domain',
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'programming_forge_selection' => $programmingForge,
                'foreign_flow_rejection' => $foreignFlow,
                'undeclared_domain_rejection' => $undeclared,
                'available_domains' => $service->domains(),
                'programming_flows' => $service->flowsFor('programming'),
                'flows_to' => AtlasSystemGraphDomainProfileFlowService::FLOWS_TO,
                'depends_on' => AtlasSystemGraphDomainProfileFlowService::DEPENDS_ON,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_profile_flow_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
