<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part07Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 7 — Run gate, confirmation
 * token, streaming policy, repair-loop and surface-boundary decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part07 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented §10.4 →
 * §12.1 slice with safe defaults: the Run gate (accept + each documented
 * rejection), the confirmation-token contract and a redemption decision, the
 * locked streaming plan, the failure_signature + repair-loop decisions
 * (continue/retry/stop/escalate) and the surface adapter boundary, then emits
 * the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
 */
class AtlasDevEffProgFlowRunbookV1Part07Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part07 {--json}';

    protected $description = 'Atlas Dev efficient programming flow runbook (Parte 7) · evaluate Run gate, confirmation token, streaming policy, repair-loop decision and surface boundary.';

    public function handle(AtlasDevEffProgFlowRunbookV1Part07Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'run_gate_accept' => $service->evaluateRunGate(true, true, $service::TOKEN_VALID),
                'run_gate_missing_confirmation' => $service->evaluateRunGate(false, true, $service::TOKEN_VALID),
                'run_gate_bad_hash' => $service->evaluateRunGate(true, false, $service::TOKEN_VALID),
                'run_gate_token_reused' => $service->evaluateRunGate(true, true, $service::TOKEN_REUSED),
                'run_gate_key_missing' => $service->evaluateRunGate(true, true, $service::TOKEN_VALID, false),
                'confirmation_token_contract' => $service->confirmationTokenContract(),
                'token_valid' => $service->decideConfirmationToken(true, true, true, false, 10),
                'token_expired' => $service->decideConfirmationToken(true, true, true, false, 301),
                'token_other_plan' => $service->decideConfirmationToken(true, false, true, false, 10),
                'streaming_plan' => $service->streamingPlan(),
                'failure_signature' => $service->failureSignatureOf('verifying', 'PHPUnit failure: 1 failed'),
                'repair_green' => $service->decideRepairLoopStep(true, 0, 3, false, false, false),
                'repair_retry' => $service->decideRepairLoopStep(false, 0, 3, false, false, false),
                'repair_same_signature_escalate' => $service->decideRepairLoopStep(false, 1, 3, true, false, false),
                'repair_diff_growth_escalate' => $service->decideRepairLoopStep(false, 0, 3, false, true, false),
                'repair_budget_stop' => $service->decideRepairLoopStep(false, 3, 3, false, false, false),
                'surface_boundary' => $service->surfaceAdapterBoundary(),
                'surface_inputs_ok' => $service->evaluateSurfaceInputs(['raw_intent', 'workspace', 'ux_selections']),
                'surface_inputs_violation' => $service->evaluateSurfaceInputs(['raw_intent', 'final_prompt']),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_v1_part07_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
