<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasStrategicDecisionRoutingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Strategic Decision Domain · route decider CLI.
 *
 *   php artisan atlas:aaeos:strategic-decision-routing [--json]
 *
 * Read-only, deterministic. Given a parsed human intent it prints the single
 * governed route (programming.dev / programming.forge / strategic_decision.review
 * / blocked) with reasons, missing evidence and the gate still owed. Never
 * executes a provider, applies a patch or runs a gate.
 *
 * @see docs/engineering-knowledge-base/domains/strategic_decision.md
 */
class AtlasStrategicDecisionRoutingCommand extends Command
{
    protected $signature = 'atlas:aaeos:strategic-decision-routing
        {--title= : Short human-readable decision title}
        {--scope=dev : dev (short single-surface) | forge (full multi-surface) | review (decision only)}
        {--risk=medium : low | medium | high | critical}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Strategic Decision · route a human request to Dev / Forge / Review / Blocked behind the runtime gate.';

    public function handle(AtlasStrategicDecisionRoutingService $service): int
    {
        try {
            // Safe defaults model the doc "Bug curto em login -> Atlas Dev":
            // a dev-sized, medium-risk request whose upstream flow already holds.
            $route = $service->route([
                'title' => (string) ($this->option('title') ?: 'short login bug'),
                'scope' => (string) $this->option('scope'),
                'risk' => (string) $this->option('risk'),
                'human_intent_model' => true,
                'product_truth_contract' => true,
                'runtime_gate' => true,
                'has_evidence' => true,
                'is_executable' => true,
                'has_work_packet' => true,
                'operator_approval' => true,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'route' => $route,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'strategic_decision_routing_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
