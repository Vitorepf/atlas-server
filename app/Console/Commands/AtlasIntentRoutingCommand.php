<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasIntentRoutingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Intent Routing kernel step · route decider CLI.
 *
 *   php artisan atlas:aaeos:intent-routing [--text=...] [--json]
 *
 * Read-only, deterministic. Given an operation envelope it prints the intent,
 * initial risk, task type and clarification need for the next kernel step.
 * It never selects a provider and never executes a tool (doc invariant).
 *
 * @see docs/engineering-knowledge-base/system-graph/intent-routing.md
 */
class AtlasIntentRoutingCommand extends Command
{
    protected $signature = 'atlas:aaeos:intent-routing
        {--text= : The operator request text to classify}
        {--blocks-security : Mark the intent as blocking security (forces clarification)}
        {--blocks-scope : Mark the intent as blocking scope (forces clarification)}
        {--blocks-autonomy : Mark the intent as blocking autonomy (forces clarification)}
        {--ambiguous : Mark the envelope as ambiguous}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Intent Routing · classify an operation envelope into intent / initial risk / task type / clarification need (no provider chosen).';

    public function handle(AtlasIntentRoutingService $service): int
    {
        try {
            // Safe default models the doc Example: "Refatora Decide" must route
            // as a programming task, not free conversation.
            $route = $service->route([
                'text' => (string) ($this->option('text') ?: 'Refatora Decide'),
                'blocks_security' => (bool) $this->option('blocks-security'),
                'blocks_scope' => (bool) $this->option('blocks-scope'),
                'blocks_autonomy' => (bool) $this->option('blocks-autonomy'),
                'ambiguous' => (bool) $this->option('ambiguous'),
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'route' => $route,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'intent_routing_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
