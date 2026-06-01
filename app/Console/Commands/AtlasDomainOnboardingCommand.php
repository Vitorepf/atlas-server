<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainOnboardingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Domain Onboarding "Domain Test" decider CLI.
 *
 *   php artisan atlas:aaeos:domain-onboarding [--json]
 *
 * Read-only and deterministic. Runs the canonical "Domain Test": is a candidate a
 * real domain (all seven distinctness criteria + a canonical family) or merely
 * Business Context? With safe defaults it shows a fully-distinct Programming
 * candidate being accepted as a `domain` and born on the `scaffold` rung.
 *
 * @see docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
 */
class AtlasDomainOnboardingCommand extends Command
{
    protected $signature = 'atlas:aaeos:domain-onboarding {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Domain Onboarding: run the Domain Test (7 distinctness criteria + family gate) to classify domain vs Business Context.';

    public function handle(AtlasDomainOnboardingService $service): int
    {
        try {
            // Safe default: a fully-distinct Programming candidate -> accepted as
            // a domain and clamped to the first maturity rung (scaffold).
            $decision = $service->classify([
                'name' => 'Programming',
                'intents_and_flows' => true,
                'context_model' => true,
                'specialist_profiles' => true,
                'tools_or_runtimes' => true,
                'gates_and_evidence' => true,
                'memory_projection' => true,
                'learning_loop' => true,
                'family' => 'Programming',
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_onboarding_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
