<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryPatchRequestContractService;
use App\Services\Ai\Product\AtlasProductDeliveryRuntimeReceiptService;
use Illuminate\Console\Command;

class AtlasProductDeliveryPatchRequestCommand extends Command
{
    protected $signature = 'atlas:product-delivery:patch-request
        {request : Human product/delivery request}
        {--workspace= : Workspace slug/path}
        {--target=provider : Patch proposal target: provider, subagent, human, forge_workcell}
        {--evidence=* : Existing proof evidence}
        {--persist : Persist an append-only AEDPDS runtime receipt}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless patch proposal is required or proof is ready}';

    protected $description = 'Builds a provider-safe AEDPDS patch request contract without invoking providers or writing files.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime,
        AtlasProductDeliveryPatchRequestContractService $patchRequest,
        AtlasProductDeliveryRuntimeReceiptService $receipts,
    ): int {
        $delivery = $deliveryRuntime->plan([
            'human_request' => (string) $this->argument('request'),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'evidence' => $this->evidenceFromOptions((array) $this->option('evidence')),
        ]);
        $payload = $patchRequest->build(
            delivery: $delivery,
            proof: is_array($delivery['proof_preview'] ?? null) ? $delivery['proof_preview'] : [],
            repairBridge: is_array($delivery['repair_bridge'] ?? null) ? $delivery['repair_bridge'] : [],
            options: [
                'target' => (string) ($this->option('target') ?: 'provider'),
            ],
        );
        if ((bool) $this->option('persist')) {
            $record = $receipts->record('patch_request', $payload);
            $payload['persisted_receipt'] = $receipts->envelope($record);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Patch request', (string) $payload['status']);
            $this->components->twoColumnDetail('target', (string) $payload['target']);
            $this->components->twoColumnDetail('hash', (string) $payload['patch_request_hash']);
        }

        if ((bool) $this->option('strict')
            && ! in_array($payload['status'] ?? null, ['ready_for_patch_proposal', 'not_required'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<string,list<string>>
     */
    private function evidenceFromOptions(array $items): array
    {
        $evidence = [];
        foreach ($items as $item) {
            if (! is_scalar($item) || trim((string) $item) === '') {
                continue;
            }
            $kind = trim((string) $item);
            $evidence[$kind] = match ($kind) {
                'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
                'security' => ['abuse cases reviewed'],
                'acceptance_mapping' => ['tests mapped to acceptance'],
                'outcome' => ['outcome memory recorded'],
                default => [$kind.' recorded'],
            };
        }

        return $evidence;
    }
}
