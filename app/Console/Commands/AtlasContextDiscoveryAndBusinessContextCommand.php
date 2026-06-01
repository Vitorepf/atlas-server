<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContextDiscoveryAndBusinessContextService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas SDD Context Discovery And Business Context · readiness decider CLI.
 *
 *   php artisan atlas:aaeos:context-discovery-and-business-context [--json]
 *
 * Read-only, deterministic. Prints the context-discovery checklist, the pre-spec
 * business questions, the confidence classes, and runs the documented readiness
 * gate against a safe default: the three load-bearing business questions all
 * confirmed, risk medium, gates available -> may execute without asking.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
 */
class AtlasContextDiscoveryAndBusinessContextCommand extends Command
{
    protected $signature = 'atlas:aaeos:context-discovery-and-business-context {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas SDD Context Discovery And Business Context · evaluate context-discovery readiness (inputs checklist, business questions, confidence classes, execute-without-asking gate). Read-only.';

    public function handle(AtlasContextDiscoveryAndBusinessContextService $service): int
    {
        try {
            // Safe default models a fully grounded, low-stakes request: the three
            // required business questions are confirmed facts, risk is medium and
            // gates can run -> the doc allows executing without asking.
            $readiness = $service->evaluateReadiness(
                requiredFieldConfidence: [
                    'business_object' => AtlasContextDiscoveryAndBusinessContextService::CONFIDENCE_CONFIRMED_FACT,
                    'action_requested' => AtlasContextDiscoveryAndBusinessContextService::CONFIDENCE_CONFIRMED_FACT,
                    'governing_rule' => AtlasContextDiscoveryAndBusinessContextService::CONFIDENCE_STRONG_INFERENCE,
                ],
                risk: 'medium',
                gatesAvailable: true,
            );

            $this->line((string) json_encode([
                'ok' => true,
                'snapshot' => $service->snapshot(),
                'readiness' => $readiness,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'context_discovery_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
