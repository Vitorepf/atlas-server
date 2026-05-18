<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationRun;
use App\Models\AiReceipt;
use App\Services\Ai\Evidence\ReceiptService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges Automation runs into the Evidence/Certification Runtime (Meta 4).
 *
 * Tolerant: when `ai_receipts` is absent or ReceiptService cannot be
 * resolved (Meta 4 missing), the bridge returns `null` and the caller
 * proceeds without external evidence. The Automation Runtime never depends
 * on Evidence being installed to produce a deterministic plan.
 */
class AutomationEvidenceBridge
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function emitPlanningReceipt(AiAutomationRun $run, array $metadata): ?AiReceipt
    {
        if (! Schema::hasTable('ai_receipts') || ! class_exists(ReceiptService::class)) {
            return null;
        }

        try {
            $service = $this->container->make(ReceiptService::class);

            return $service->emit([
                'receipt_type' => 'domain_step',
                'target_type' => 'automation_run',
                'target_id' => $run->id,
                'mission_id' => $run->mission_id,
                'work_order_id' => $run->work_order_id,
                'actor_type' => 'system',
                'action' => 'automation.plan_emitted',
                'input_hash' => AutomationCanonicalHash::sha256(['run' => $run->uuid, 'run_kind' => $run->run_kind]),
                'output_hash' => AutomationCanonicalHash::sha256($metadata),
                'evidence_refs' => $metadata,
                'status' => 'ok',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
