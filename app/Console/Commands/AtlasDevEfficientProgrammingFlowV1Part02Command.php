<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part02Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 2 — fast-path decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part02 [--json]
 *
 * Read-only, deterministic. Exercises the documented slice (§9–§18) with safe
 * defaults: a legal/illegal state transition, an initial-mode classification, an
 * R-level envelope, a context char-budget overflow, and a scope-guard verdict,
 * then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowV1Part02Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part02 {--json}';

    protected $description = 'Atlas Dev efficient programming flow (Parte 2) · evaluate state machine, R-level envelope, context budget and scope guard.';

    public function handle(AtlasDevEfficientProgrammingFlowV1Part02Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'transition_legal' => $service->evaluateTransition('verifying', 'failed'),
                'transition_illegal' => $service->evaluateTransition('completed', 'executing'),
                'classify_patch' => $service->classifyInitialMode('patch'),
                'risk_envelope_r3' => $service->riskEnvelope('R3'),
                'repair_over_cap_r2' => $service->repairDecision('R2', 2),
                'write_gates' => $service->writeGateRequirements(true),
                'budget_overflow_read_only' => $service->applyBudgetOverflow(
                    'read_only',
                    9000,
                    ['core', 'code_intelligence'],
                    ['code_intelligence']
                ),
                'scope_guard_forbidden' => $service->scopeGuardVerdict(
                    ['config/auth.php'],
                    [],
                    2,
                    1
                ),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_v1_part02_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
