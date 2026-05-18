<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolInvocation;
use App\Models\AiToolReceipt;
use App\Services\Ai\Evidence\ReceiptService as EvidenceReceiptService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ToolReceiptService
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     */
    public function emit(AiToolInvocation $invocation, string $status, array $policy, ?array $output = null): AiToolReceipt
    {
        $evidenceRefs = $this->bridgeToEvidenceRuntime($invocation, $status, $policy, $output);

        $hashInput = [
            'tool_invocation_id' => $invocation->id,
            'status' => $status,
            'input_hash' => $invocation->input_hash,
            'output_hash' => $invocation->output_hash,
            'policy_decision_ref' => $invocation->policy_decision_ref,
            'evidence_refs' => $evidenceRefs,
        ];

        return AiToolReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_invocation_id' => $invocation->id,
            'receipt_type' => 'tool_call',
            'status' => $status,
            'evidence_refs' => $evidenceRefs,
            'receipt_hash' => MissionCanonicalHash::sha256($hashInput),
        ]);
    }

    public function bridgeAvailable(): bool
    {
        return Schema::hasTable('ai_receipts');
    }

    /**
     * Bridge to Atlas Evidence / Certification Runtime (Meta 4). Tolerant: if the
     * Evidence Runtime is unavailable, we keep a local-only receipt and note the
     * missing bridge. We do NOT silently swallow real exceptions.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $output
     * @return array<int,array<string,mixed>>
     */
    private function bridgeToEvidenceRuntime(AiToolInvocation $invocation, string $status, array $policy, ?array $output): array
    {
        if (! $this->bridgeAvailable()) {
            return [[
                'kind' => 'tool_runtime_local_receipt',
                'reason' => 'evidence_runtime_unavailable',
                'tool_invocation_id' => $invocation->id,
            ]];
        }

        try {
            $receiptService = $this->container->make(EvidenceReceiptService::class);
            $receipt = $receiptService->emit([
                'receipt_type' => 'tool_call',
                'status' => $this->mapStatusForEvidence($status),
                'target_type' => 'tool_invocation',
                'target_id' => $invocation->id,
                'mission_id' => $invocation->mission_id,
                'work_order_id' => $invocation->work_order_id,
                'actor_type' => 'atlas_tool_runtime',
                'action' => (string) ($invocation->tool->tool_id ?? 'tool'),
                'input_hash' => $invocation->input_hash,
                'output_hash' => $invocation->output_hash,
                'evidence_refs' => $output !== null ? [['kind' => 'tool_runtime_mock_output', 'output_hash' => $invocation->output_hash]] : [],
            ]);

            return [[
                'kind' => 'evidence_runtime_receipt',
                'receipt_id' => (string) $receipt->id,
                'receipt_hash' => (string) $receipt->receipt_hash,
            ]];
        } catch (Throwable $e) {
            return [[
                'kind' => 'tool_runtime_local_receipt',
                'reason' => 'evidence_runtime_exception:'.$e->getMessage(),
                'tool_invocation_id' => $invocation->id,
            ]];
        }
    }

    private function mapStatusForEvidence(string $status): string
    {
        return match ($status) {
            'succeeded' => 'ok',
            'failed' => 'failed',
            default => 'partial',
        };
    }
}
