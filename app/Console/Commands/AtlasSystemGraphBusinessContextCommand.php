<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphBusinessContextService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Business Context kernel step · context decider CLI.
 *
 *   php artisan atlas:aaeos:business-context-sysgraph [--obra=...] [--json]
 *
 * Read-only, deterministic. Given a routed intent + envelope it prints the
 * business context (Obra, workspace, project, product, environment), the
 * sensitivity / priority / risk bands and whether it may hand off to
 * domain-profile-flow. It never selects a provider, never chooses the cognitive
 * domain and never mutates policy (doc invariants).
 *
 * @see docs/engineering-knowledge-base/system-graph/business-context.md
 */
class AtlasSystemGraphBusinessContextCommand extends Command
{
    protected $signature = 'atlas:aaeos:business-context-sysgraph
        {--obra= : The Obra (work) the task belongs to}
        {--workspace= : The certified workspace id}
        {--project= : The project name}
        {--product= : The product name}
        {--environment= : Deployment environment (e.g. dev|staging|prod)}
        {--sensitivity= : public|internal|sensitive|secret}
        {--policy-cleared : Policy Profile has cleared this context}
        {--business-critical : Mark the context as business-critical (high priority)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Business Context · anchor a routed intent to its Obra/workspace/product and decide sensitivity / priority / hand-off (no provider, no domain, no policy mutation).';

    public function handle(AtlasSystemGraphBusinessContextService $service): int
    {
        try {
            // Safe default models the doc Example: a programming task in Atlas Code
            // must know its Obra, repo and objective before building context.
            $context = $service->resolve([
                'obra' => (string) ($this->option('obra') ?: 'atlas-code'),
                'workspace' => (string) ($this->option('workspace') ?: 'atlas-server'),
                'project' => $this->option('project') !== null ? (string) $this->option('project') : 'atlas',
                'product' => $this->option('product') !== null ? (string) $this->option('product') : null,
                'environment' => (string) ($this->option('environment') ?: 'dev'),
                'sensitivity' => (string) ($this->option('sensitivity') ?: 'internal'),
                'policy_profile_cleared' => (bool) $this->option('policy-cleared'),
                'business_critical' => (bool) $this->option('business-critical'),
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'business_context' => $context,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'business_context_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
