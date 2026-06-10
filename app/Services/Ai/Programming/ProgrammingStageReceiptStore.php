<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingStageReceipt;
use App\Services\Ai\Support\DatabaseTableAvailability;

class ProgrammingStageReceiptStore
{
    public function __construct(
        private readonly ProgrammingStageReceiptValidator $validator,
    ) {}

    /**
     * @param  array<int,string>  $stages
     * @return array<int,array<string,mixed>>
     */
    public function expectedReceipts(string $planId, ?string $parentPlanId, array $stages = ['plan', 'review', 'patch', 'test', 'repair']): array
    {
        return collect($stages)
            ->map(fn (string $stage, int $index): array => $this->make(
                planId: $planId,
                parentPlanId: $parentPlanId,
                stage: $stage,
                attempt: 1,
                status: 'pending',
                input: ['plan_id' => $planId, 'stage' => $stage],
                output: ['expected_order' => $index + 1],
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $output
     * @return array<string,mixed>
     */
    public function make(string $planId, ?string $parentPlanId, string $stage, int $attempt, string $status, array $input, array $output, array $evidenceRefs = [], bool $persist = false): array
    {
        $receipt = [
            'schema_version' => 'atlas.programming.stage_receipt.v1',
            'receipt_id' => hash('sha256', $planId.'|'.$stage.'|'.$attempt),
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'stage' => $stage,
            'attempt' => $attempt,
            'status' => $status,
            'evidence_refs' => $evidenceRefs,
            'input_hash' => hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'output_hash' => hash('sha256', json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'created_at' => now()->toJSON(),
        ];
        $receipt['validation'] = $this->validator->validate($receipt);

        if ($persist) {
            return $this->persist($receipt);
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function persist(array $receipt): array
    {
        if (! $this->storageAvailable()) {
            return array_merge($receipt, [
                'storage' => [
                    'persisted' => false,
                    'reason' => 'atlas_programming_stage_receipts_table_missing',
                ],
            ]);
        }

        $validation = $this->validator->validate($receipt);
        $payload = array_merge($receipt, ['validation' => $validation]);

        $model = AtlasProgrammingStageReceipt::query()->updateOrCreate(
            ['receipt_id' => (string) $payload['receipt_id']],
            [
                'plan_id' => (string) $payload['plan_id'],
                'parent_plan_id' => $payload['parent_plan_id'] ?? null,
                'stage' => (string) $payload['stage'],
                'attempt' => (int) $payload['attempt'],
                'status' => (string) $payload['status'],
                'input_hash' => (string) $payload['input_hash'],
                'output_hash' => (string) $payload['output_hash'],
                'evidence_refs_json' => $payload['evidence_refs'] ?? [],
                'payload_json' => $payload,
                'validation_json' => $validation,
            ],
        );

        return array_merge($payload, [
            'storage' => [
                'persisted' => true,
                'model_id' => $model->id,
                'table' => 'atlas_programming_stage_receipts',
            ],
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function timeline(string $planId): array
    {
        if (! $this->storageAvailable()) {
            return [];
        }

        return AtlasProgrammingStageReceipt::query()
            ->where('plan_id', $planId)
            ->orderBy('attempt')
            ->orderByRaw("CASE stage WHEN 'plan' THEN 10 WHEN 'review' THEN 20 WHEN 'patch' THEN 30 WHEN 'test' THEN 40 WHEN 'repair' THEN 50 ELSE 90 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (AtlasProgrammingStageReceipt $receipt): array => array_merge(
                $receipt->payload_json ?? [],
                [
                    'storage' => [
                        'persisted' => true,
                        'model_id' => $receipt->id,
                        'table' => 'atlas_programming_stage_receipts',
                    ],
                ],
            ))
            ->values()
            ->all();
    }

    private function storageAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_programming_stage_receipts');
    }
}
