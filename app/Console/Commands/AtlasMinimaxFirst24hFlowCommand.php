<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMinimaxFirst24hFlowService;
use Illuminate\Console\Command;
use Throwable;

/**
 * MiniMax-First 24h Agentic Engineering Flow — policy decider CLI.
 *
 *   php artisan atlas:aaeos:minimax-first-24h-flow [--json]
 *
 * Read-only, deterministic. With no further flags it emits the full canonical
 * contract (deficit table, worker model, ordered flow, work-plan fields, provider
 * roles) plus a worked example of the work-plan gate using a deliberately
 * incomplete plan, so the writer-block rule is visible.
 *
 * @see docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
 */
class AtlasMinimaxFirst24hFlowCommand extends Command
{
    protected $signature = 'atlas:aaeos:minimax-first-24h-flow
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · MiniMax-first 24h flow decider (work-plan / writer / DeepSeek-escalation / hard-stops).';

    public function handle(AtlasMinimaxFirst24hFlowService $service): int
    {
        try {
            // Safe default cycle: a code-touching cycle whose work plan is missing
            // the writer-gate fields, so the receipt shows the writer being blocked.
            $workPlanDecision = $service->decideWorkPlan([
                'touches_code' => true,
                'deficit_patterns' => ['huge_repo_single_call'],
                'wants_writer' => true,
                'work_plan' => [
                    'objective' => 'scout-and-spec',
                    'repo_scope' => 'atlas-server',
                    // ownership_map / allowed_files / output_contract intentionally absent.
                ],
            ]);

            $payload = [
                'ok' => true,
                'contract' => $service->contract(),
                'work_plan_decision_example' => $workPlanDecision,
                'deepseek_easier_is_blocked' => $service->decideDeepSeekEscalation([
                    'reason' => 'it_seems_easier',
                    'consumes_context_pack' => true,
                ]),
            ];

            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'minimax_first_24h_flow_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
