<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part08Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 8 — escalation +
 * GO operational intake invariant decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part08 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented §12.2
 * and §15.1 slice with safe defaults: the escalation target mapping (forge /
 * obra_candidate / none), the §15.1.5 Run-intake error precedence, the §15.1.3
 * stage gate, the §15.1.4 confirmation-token verdict, the §15.1.2 APP_KEY
 * check, the §15.1.7 path redaction and the §15.1.9 flow-identity check, then
 * emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md
 */
class AtlasDevEffProgFlowRunbookV1Part08Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part08 {--json}';

    protected $description = 'Atlas Dev efficient programming flow runbook (Parte 8) · escalation target, Run-intake error precedence, stage gate, confirmation token, path redaction and flow identity.';

    public function handle(AtlasDevEffProgFlowRunbookV1Part08Service $service): int
    {
        try {
            $token = $service->evaluateConfirmationToken(
                ageSeconds: 120,
                bindingMatches: true,
                alreadyConsumed: false,
            );

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'escalation_forge_by_score' => $service->decideEscalationTarget(8, 'R2'),
                'escalation_forge_by_risk' => $service->decideEscalationTarget(2, 'R4'),
                'escalation_obra_band' => $service->decideEscalationTarget(5, 'R2'),
                'escalation_none' => $service->decideEscalationTarget(2, 'R1'),
                'intake_accepted' => $service->validateRunIntake(
                    ['run_id' => 'r1', 'task_contract_hash' => 'abc', 'confirmation_token' => 'tok', 'operator_confirmed' => true],
                    ['task_contract_hash' => 'abc', 'token_present' => true, 'token_status' => $token['token_status']],
                ),
                'intake_operator_not_confirmed' => $service->validateRunIntake(
                    ['operator_confirmed' => 'true'],
                ),
                'intake_hash_mismatch' => $service->validateRunIntake(
                    ['operator_confirmed' => true, 'task_contract_hash' => 'wrong'],
                    ['task_contract_hash' => 'abc'],
                ),
                'confirmation_token_valid' => $token,
                'confirmation_token_expired' => $service->evaluateConfirmationToken(400, true, false),
                'gate_run_requires_plan' => $service->resolveStageGate($service::STAGE_RUN, false, true),
                'gate_plan_enabled' => $service->resolveStageGate($service::STAGE_PLAN, true, false),
                'app_key_too_short' => $service->evaluateAppKey('base64:'.base64_encode('short')),
                'redaction' => $service->redactHttpResponse(
                    '/Users/op/develop/Atlas/atlas-server',
                    'run-123',
                    ['/Users/op/develop/Atlas/atlas-server/storage/atlas-dev/receipts/run-123/verification.json'],
                    'wsh_abc123',
                ),
                'flow_identity_canonical' => $service->validateFlowIdentity(
                    ['flow_id' => 'atlas_dev', 'flow_origin' => 'atlas_ai_router', 'command_intent' => 'patch'],
                ),
                'flow_identity_bad_origin' => $service->validateFlowIdentity(
                    ['flow_id' => 'atlas_dev', 'flow_origin' => 'somewhere_else'],
                ),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_v1_part08_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
