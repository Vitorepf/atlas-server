<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Models\AiToolInvocation;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ToolInvocationService
{
    public function __construct(
        private readonly ToolPolicyBridgeService $policyBridge,
        private readonly ToolReceiptService $receipts,
    ) {}

    /**
     * Invoke a tool through the safe contract pipeline:
     *   1) policy gate (bridge to Meta 3) — strict mode throws
     *      `ToolPolicyEnforcementException` when the Policy runtime is
     *      unavailable or fails. We catch and mark the invocation `blocked`
     *      with `error_summary` so the failure is visible to callers.
     *   2) mock execution (no external side effects in Meta 5)
     *   3) emit local receipt + bridge to Evidence Runtime (Meta 4) — strict
     *      mode throws `ToolReceiptEmissionException` when Evidence is
     *      unavailable. We catch and mark the invocation `blocked` so a
     *      successful execution never closes without a durable receipt.
     *
     * The Tool Runtime does NOT execute real external actions in Meta 5.
     * external_action / financial_action / security_sensitive tools without an
     * `allow` decision are recorded as `denied` or `blocked` with no execution.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     */
    public function invoke(AiToolDefinition $tool, array $input = [], array $context = []): AiToolInvocation
    {
        $inputHash = MissionCanonicalHash::sha256($input);

        // Phase 1 · Policy gate. In strict mode this can throw before we ever
        // create the invocation row. Create the row first so the failure is
        // observable as a `blocked` invocation (never a silent miss).
        try {
            $policy = $this->policyBridge->evaluate($tool, $context);
            $decision = (string) ($policy['decision'] ?? 'deny');
        } catch (ToolPolicyEnforcementException $e) {
            return $this->createBlockedInvocationFromPolicyFailure($tool, $input, $context, $inputHash, $e);
        }

        $invocation = AiToolInvocation::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_definition_id' => $tool->id,
            'capability_id' => $context['capability_id'] ?? null,
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'invocation_status' => $this->initialStatusFromDecision($decision),
            'input_hash' => $inputHash,
            'output_hash' => null,
            'policy_decision_ref' => $policy['receipt_hash'] ?? null,
            'evidence_refs' => null,
            'error_summary' => null,
        ]);

        if ($invocation->invocation_status === 'denied' || $invocation->invocation_status === 'blocked') {
            $this->emitReceiptOrMarkBlocked($invocation, 'failed', $policy, null);

            return $invocation->refresh();
        }

        $invocation->invocation_status = 'running';
        $invocation->started_at = Carbon::now();
        $invocation->save();

        $output = $this->mockExecute($tool, $input, $context);
        $outputHash = MissionCanonicalHash::sha256($output);

        $invocation->output_hash = $outputHash;
        $invocation->invocation_status = 'succeeded';
        $invocation->finished_at = Carbon::now();
        $invocation->save();

        $this->emitReceiptOrMarkBlocked($invocation, 'succeeded', $policy, $output);

        return $invocation->refresh();
    }

    /**
     * Persist a `blocked` invocation when the Policy bridge fails in strict
     * mode. The invocation row carries the policy failure reason in
     * `error_summary` so the Control Plane and Evidence ledger can surface
     * it. A receipt is best-effort: if Evidence is also down, the structured
     * log + audit event emitted by the bridges remain the durable signal.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     */
    private function createBlockedInvocationFromPolicyFailure(
        AiToolDefinition $tool,
        array $input,
        array $context,
        string $inputHash,
        ToolPolicyEnforcementException $e,
    ): AiToolInvocation {
        $invocation = AiToolInvocation::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_definition_id' => $tool->id,
            'capability_id' => $context['capability_id'] ?? null,
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'invocation_status' => 'blocked',
            'input_hash' => $inputHash,
            'output_hash' => null,
            'policy_decision_ref' => null,
            'evidence_refs' => null,
            'error_summary' => 'policy_enforcement_failed:'.$e->reason.':'.$e->getMessage(),
        ]);

        $policy = [
            'decision' => 'blocked',
            'source' => 'tool_policy_enforcement_exception',
            'reason' => $e->reason,
            'receipt_hash' => null,
            'gate_id' => null,
            'degraded' => true,
            'mode' => 'strict',
        ];
        $this->emitReceiptOrMarkBlocked($invocation, 'failed', $policy, null);

        return $invocation->refresh();
    }

    /**
     * Emit the local + Evidence receipt; if strict mode and Evidence fails,
     * convert the invocation to `blocked` with `error_summary` instead of
     * letting the exception escape unhandled.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     */
    private function emitReceiptOrMarkBlocked(AiToolInvocation $invocation, string $status, array $policy, ?array $output): void
    {
        try {
            $this->receipts->emit($invocation, $status, $policy, $output);
        } catch (ToolReceiptEmissionException $e) {
            // Receipt could not be persisted in strict mode. Mark the
            // invocation `blocked` so an executed tool never closes
            // `succeeded` without durable evidence. Preserve any existing
            // root-cause `error_summary` (e.g., the original policy failure
            // when both bridges were down) instead of overwriting it.
            $invocation->invocation_status = 'blocked';
            $rootCause = trim((string) $invocation->error_summary);
            $receiptCause = 'receipt_emission_failed:'.$e->reason.':'.$e->getMessage();
            $invocation->error_summary = $rootCause === ''
                ? $receiptCause
                : $rootCause.' | '.$receiptCause;
            $invocation->finished_at = Carbon::now();
            $invocation->save();
        }
    }

    private function initialStatusFromDecision(string $decision): string
    {
        return match ($decision) {
            'allow' => 'allowed',
            'deny' => 'denied',
            'require_approval' => 'blocked',
            'blocked' => 'blocked',
            default => 'blocked',
        };
    }

    /**
     * Local mock executor for Meta 5 — generates a deterministic synthetic output
     * shaped by the tool's `output_schema`. Does NOT perform real I/O against
     * filesystem, network, browser, GitHub or APIs. Real executors will be
     * registered in future metas behind the same contract.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function mockExecute(AiToolDefinition $tool, array $input, array $context): array
    {
        return [
            'tool_id' => $tool->tool_id,
            'mode' => 'meta_5_mock',
            'input_keys' => array_keys($input),
            'context_keys' => array_keys($context),
            'output_schema_kind' => $tool->output_schema['type'] ?? 'object',
            'note' => 'Meta 5 emits contract-conformant mock output. No real external side effects.',
        ];
    }
}
