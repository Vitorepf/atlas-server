<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Reconciles normalized cost events against expected task/run pairs without
 * importing, persisting, or querying any external billing system.
 */
final class AgentControlPlaneCostImportReconciliationDryRun
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    /**
     * @param  list<array<string, mixed>>  $normalizedEvents
     * @param  list<array<string, mixed>>  $expectedRefs
     * @return array<string, mixed>
     */
    public function reconcile(array $normalizedEvents, array $expectedRefs): array
    {
        $seen = [];
        foreach ($normalizedEvents as $event) {
            $seen[$this->refKey($event)] = true;
        }

        $missing = [];
        foreach ($expectedRefs as $ref) {
            if (! isset($seen[$this->refKey($ref)])) {
                $missing[] = [
                    'task_packet_id' => (string) ($ref['task_packet_id'] ?? ''),
                    'run_id' => (string) ($ref['run_id'] ?? ''),
                    'reason' => 'expected_cost_event_missing',
                ];
            }
        }

        $payload = [
            'status' => $missing === [] ? 'reconciliation_dry_run_clear' : 'reconciliation_dry_run_has_gaps',
            'expected_ref_count' => count($expectedRefs),
            'observed_ref_count' => count($seen),
            'missing_cost_event_refs' => $missing,
            'missing_count' => count($missing),
            'import_allowed' => false,
            'cost_events_write_allowed' => false,
            'provider_billing_api_read_allowed' => false,
            'dry_run_only' => true,
        ];
        $payload['reconciliation_dry_run_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function refKey(array $value): string
    {
        return (string) ($value['task_packet_id'] ?? '').'|'.(string) ($value['run_id'] ?? '');
    }

    private function stableHash(array $payload): string
    {
        unset($payload['reconciliation_dry_run_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
