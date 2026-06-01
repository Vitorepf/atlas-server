<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainPlaneService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Domain Plane decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-domain-plane [--json]
 *
 * Read-only and deterministic. With the safe default it selects the doc's
 * worked example domains (programming -> SDD/Forge/diff/gates/evidence;
 * research -> search/sources/curation), shows that an unknown token does NOT
 * default to programming (doc "Riscos"), and shows the provider-invariant guard
 * rejecting a domain as a provider decision-maker.
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-plane.md
 */
class AtlasDomainPlaneCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-domain-plane {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas · Domain Plane decider (catalog of cognitive domains, profile selection, provider invariant).';

    public function handle(AtlasDomainPlaneService $service): int
    {
        try {
            // Doc example: programming activates SDD, Forge, diff, gates, evidence.
            $programming = $service->select(['domain' => 'programming', 'selection_ref' => 'doc-example-programming']);

            // Doc example: research activates search, sources, curation.
            $research = $service->select(['domain' => 'research', 'selection_ref' => 'doc-example-research']);

            // Doc "Riscos": an unknown token must NOT default to programming.
            $unknown = $service->select(['domain' => 'crypto_trading_bot', 'selection_ref' => 'doc-risk-no-default']);

            // Doc invariant: a domain never decides a provider.
            $guard = $service->guardDecisionMaker(['domain' => 'programming', 'decision' => 'provider']);

            $this->line((string) json_encode([
                'ok' => true,
                'programming_selection' => $programming,
                'research_selection' => $research,
                'unknown_selection' => $unknown,
                'provider_guard' => $guard,
                'available_domains' => $service->domains(),
                'flows_to' => AtlasDomainPlaneService::FLOWS_TO,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_plane_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
