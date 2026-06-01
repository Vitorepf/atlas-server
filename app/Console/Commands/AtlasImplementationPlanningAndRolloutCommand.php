<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasImplementationPlanningAndRolloutService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Implementation Planning And Rollout decider CLI.
 *
 *   php artisan atlas:aaeos:implementation-planning-and-rollout [--json]
 *
 * Read-only and deterministic. Evaluates one implementation block at a rollout
 * step and emits the verdict (proceed | blocked | stop), the required next
 * action and an audit receipt. With safe defaults (an empty, unshaped block at
 * the first step) it demonstrates the contract: an ill-shaped block is
 * `blocked` and may NOT execute its step.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md
 */
class AtlasImplementationPlanningAndRolloutCommand extends Command
{
    protected $signature = 'atlas:aaeos:implementation-planning-and-rollout {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · implementation planning and rollout decider (proceed|blocked|stop) for one reversible block.';

    public function handle(AtlasImplementationPlanningAndRolloutService $service): int
    {
        try {
            // Safe default block: nothing declared, first rollout step attempted
            // with no completed prerequisites. The block is not well-formed, so
            // the verdict must be blocked and the step may NOT execute.
            $decision = $service->evaluate([
                'shape' => [],
                'step' => 'cold_tests_and_guardrails',
                'completed_steps' => [],
                'stop_conditions' => [],
                'done' => [],
                'docs_changed' => false,
                'structural_contracts_changed' => false,
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'implementation_planning_and_rollout_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
