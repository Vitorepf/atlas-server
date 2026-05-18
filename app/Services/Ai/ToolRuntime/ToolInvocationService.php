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
     *   1) policy gate (bridge to Meta 3)
     *   2) mock execution (no external side effects in Meta 5)
     *   3) emit local receipt + bridge to Evidence Runtime (Meta 4)
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
        $policy = $this->policyBridge->evaluate($tool, $context);
        $decision = (string) ($policy['decision'] ?? 'deny');

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
            $this->receipts->emit($invocation, 'failed', $policy);

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

        $this->receipts->emit($invocation, 'succeeded', $policy, $output);

        return $invocation->refresh();
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
