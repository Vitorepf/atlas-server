<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

/**
 * Builds a non-persistent receipt plan for cost import events.
 */
final class AgentControlPlaneCostImportReceiptPlanner
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    /**
     * @param  list<array<string, mixed>>  $normalizedEvents
     * @return array<string, mixed>
     */
    public function plan(array $normalizedEvents): array
    {
        $receipts = [];
        foreach ($normalizedEvents as $event) {
            $receipts[] = [
                'receipt_kind' => 'cost_import_dry_run_receipt',
                'receipt_id' => hash('sha256', implode('|', [
                    (string) ($event['task_packet_id'] ?? ''),
                    (string) ($event['run_id'] ?? ''),
                    (string) ($event['idempotency_key'] ?? ''),
                ])),
                'task_packet_id' => (string) ($event['task_packet_id'] ?? ''),
                'run_id' => (string) ($event['run_id'] ?? ''),
                'agent_id' => (string) ($event['agent_id'] ?? ''),
                'idempotency_key' => (string) ($event['idempotency_key'] ?? ''),
                'planned_outcome' => 'cost_event_import_planned_not_written',
                'write_allowed' => false,
            ];
        }

        $payload = [
            'status' => 'cost_import_receipt_plan_ready',
            'planned_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_count' => count($receipts),
            'receipts' => $receipts,
            'ledger_write_allowed' => false,
            'cost_events_write_allowed' => false,
            'receipt_persistence_allowed' => false,
        ];
        $payload['cost_import_receipt_plan_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['planned_at'], $payload['cost_import_receipt_plan_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
