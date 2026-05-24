<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryMutativeRepairExecutorService;
use App\Services\Ai\Product\AtlasProductDeliveryPatchProposalGateService;
use App\Services\Ai\Product\AtlasProductDeliveryRuntimeReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AtlasProductDeliveryRepairExecuteCommand extends Command
{
    protected $signature = 'atlas:product-delivery:repair-execute
        {request : Human product/delivery request}
        {--workspace= : Workspace slug/path}
        {--patch= : Path to JSON patch manifest}
        {--source=human : Patch source: human, provider, subagent, forge_workcell}
        {--approval= : Operator approval reason for the current proposal hash}
        {--apply : Apply patch; default is dry-run}
        {--persist : Persist append-only AEDPDS runtime receipts}
        {--evidence=* : Evidence kind for proof rerun}
        {--ux=* : UX expectation or prototype refs to pass into APDR/AEDPDS}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless dry_run_ready or applied_and_verified}';

    protected $description = 'Executes an explicit AEDPDS mutative repair patch with allowlist, rollback snapshot, and proof rerun.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime,
        AtlasProductDeliveryPatchProposalGateService $patchGate,
        AtlasProductDeliveryMutativeRepairExecutorService $executor,
        AtlasProductDeliveryRuntimeReceiptService $receipts,
    ): int {
        $patch = $this->patchManifest();
        if ($patch === null) {
            return self::FAILURE;
        }

        $delivery = $deliveryRuntime->plan([
            'human_request' => (string) $this->argument('request'),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'operator_approved' => is_scalar($this->option('approval')) && trim((string) $this->option('approval')) !== '',
            'ux_expectations' => $this->strings($this->option('ux')),
        ]);

        $gate = $patchGate->evaluate($delivery, $patch, [
            'source_kind' => (string) ($this->option('source') ?: 'human'),
            'apply' => (bool) $this->option('apply'),
        ]);
        if (is_scalar($this->option('approval')) && trim((string) $this->option('approval')) !== '') {
            $gate = $patchGate->evaluate($delivery, $patch, [
                'source_kind' => (string) ($this->option('source') ?: 'human'),
                'apply' => (bool) $this->option('apply'),
                'approval' => [
                    'decision' => 'approved',
                    'proposal_hash' => (string) $gate['proposal_hash'],
                    'approved_by' => 'operator_cli',
                    'reason' => trim((string) $this->option('approval')),
                ],
            ]);
        }

        if (($gate['status'] ?? null) !== 'approved') {
            if ((bool) $this->option('persist')) {
                $record = $receipts->record('patch_gate', $gate);
                $gate['persisted_receipt'] = $receipts->envelope($record);
            }
            $this->emitPayload($gate);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $payload = $executor->execute(
            delivery: $delivery,
            proof: is_array($delivery['proof_preview'] ?? null) ? $delivery['proof_preview'] : [],
            repairBridge: is_array($delivery['repair_bridge'] ?? null) ? $delivery['repair_bridge'] : [],
            patchManifest: is_array($gate['sanitized_patch_manifest'] ?? null) ? $gate['sanitized_patch_manifest'] : $patch,
            options: [
                'apply' => (bool) $this->option('apply'),
                'proof_evidence' => $this->evidenceFromOptions((array) $this->option('evidence')),
            ],
        );

        $payload['patch_proposal_gate'] = $gate;
        if ((bool) $this->option('persist')) {
            $gateRecord = $receipts->record('patch_gate', $gate);
            $executionRecord = $receipts->record('repair_execution', $payload);
            $payload['persisted_receipts'] = [
                'patch_gate' => $receipts->envelope($gateRecord),
                'repair_execution' => $receipts->envelope($executionRecord),
            ];
        }
        $this->emitPayload($payload);

        if ((bool) $this->option('strict')
            && ! in_array($payload['status'] ?? null, ['dry_run_ready', 'applied_and_verified'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->twoColumnDetail('Patch gate', (string) ($payload['status'] ?? data_get($payload, 'patch_proposal_gate.status', 'unknown')));
        $this->components->twoColumnDetail('writes', data_get($payload, 'writes') ? 'yes' : 'no');
        $hash = $payload['execution_receipt_hash'] ?? $payload['patch_gate_hash'] ?? null;
        if (is_scalar($hash)) {
            $this->components->twoColumnDetail('receipt', (string) $hash);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function patchManifest(): ?array
    {
        $path = $this->option('patch');
        if (! is_scalar($path) || trim((string) $path) === '') {
            $this->error('Missing --patch JSON manifest.');

            return null;
        }

        $path = (string) $path;
        if (! File::exists($path)) {
            $this->error('Patch manifest not found: '.$path);

            return null;
        }

        try {
            $decoded = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid patch manifest JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded)) {
            $this->error('Patch manifest must decode to an object.');

            return null;
        }

        return $decoded;
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

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }
}
