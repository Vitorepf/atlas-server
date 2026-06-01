<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part05Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 5 — plan-only pipeline
 * contract decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part05 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented §9.2
 * slice with safe defaults: a task_kind classification, an R-level score, the
 * R-level file budget, the documented routing scenarios (fast-path, forge
 * preview, delegate, Gap-E read-only no-provider, blocked) and the public CLI
 * contract, then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part05Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part05 {--json}';

    protected $description = 'Atlas Dev efficient programming flow runbook (Parte 5) · classify task_kind, score R-level, derive file budget and resolve plan-only routing.';

    public function handle(AtlasDevEfficientProgrammingFlowRunbookV1Part05Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'classify_repair' => $service->classifyTaskKind('corrija o teste falhando do RiskLevelScorer'),
                'classify_question' => $service->classifyTaskKind('o que faz o RoutingDecisionEngine?'),
                'classify_risky' => $service->classifyTaskKind('ajuste o fluxo de billing do checkout'),
                'risk_repair_two_files' => $service->scoreRiskLevel($service::KIND_REPAIR, 'corrija o teste', 2, 1),
                'risk_risky_multiagent' => $service->scoreRiskLevel($service::KIND_RISKY, 'auth replay audit multiagent', 9, 4),
                'budget_r2' => $service->fitsTaskContractBudget($service::R2, 2),
                'budget_r2_overflow' => $service->fitsTaskContractBudget($service::R2, 3),
                'route_repair_fast_path' => $service->decideRouting(
                    $service::KIND_REPAIR,
                    $service::R2,
                    'high',
                    'confirmed_fact',
                    null,
                    null,
                    true,
                ),
                'route_r4_forge' => $service->decideRouting($service::KIND_RISKY, $service::R4, 'low', 'other'),
                'route_gap_e_no_provider' => $service->decideRouting(
                    $service::KIND_QUESTION,
                    $service::R0,
                    'high',
                    'confirmed_fact',
                ),
                'route_blocked' => $service->decideRouting(
                    $service::KIND_PATCH,
                    $service::R2,
                    'blocking',
                    'blocking_ambiguity',
                ),
                'route_delegate' => $service->decideRouting(
                    $service::KIND_QUESTION,
                    $service::R0,
                    'high',
                    'other',
                    'debug',
                    'atlas_ai_router',
                ),
                'cli_contract' => $service->cliContract(),
                'cli_efficient_plan_only' => $service->evaluateCliInvocation(['--efficient', '--json']),
                'cli_efficient_yes_executes' => $service->evaluateCliInvocation(['--efficient', '--yes', '--json']),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_v1_part05_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
