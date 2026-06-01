<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiRuntimeLanguageBoundariesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Runtime Language Boundaries decider CLI.
 *
 *   php artisan atlas:aaeos:runtime-language-boundaries [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the contract:
 * a mass-webhook need routes to go_edge, a Python attempt to decide the
 * provider/model is blocked (forbidden authority), and an empty invoke payload
 * is invalid because the Kernel did not sign it (no decision_receipt_hash).
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
 */
class AtlasAiRuntimeLanguageBoundariesCommand extends Command
{
    protected $signature = 'atlas:aaeos:runtime-language-boundaries {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI runtime language boundaries: route a need to its owning language, allow/block a runtime action, validate the invoke/result contract.';

    public function handle(AtlasAiRuntimeLanguageBoundariesService $service): int
    {
        try {
            // Mass webhook ingestion -> go_edge (Decision Matrix).
            $routing = $service->routeNeed('webhook_click_postback_em_massa');

            // Python trying to decide provider/model -> blocked.
            $blockedAction = $service->evaluateAction('python_ai_data', 'decide_provider_or_model');

            // Empty invoke payload -> invalid (Kernel did not sign it).
            $invoke = $service->validateInvoke([]);

            $decision = [
                'contract' => $service->contract(),
                'sample_routing' => $routing,
                'sample_blocked_action' => $blockedAction,
                'sample_empty_invoke' => $invoke,
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'runtime_language_boundaries_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
