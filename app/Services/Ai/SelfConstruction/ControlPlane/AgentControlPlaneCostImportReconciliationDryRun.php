<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

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
        $observedCounts = [];
        $invalidObservedCount = 0;
        foreach ($normalizedEvents as $event) {
            if (! $this->isValidRef($event)) {
                $invalidObservedCount++;

                continue;
            }
            $key = $this->refKey($event);
            $observedCounts[$key] = ($observedCounts[$key] ?? 0) + 1;
        }

        $duplicateObservedRefs = [];
        foreach ($observedCounts as $key => $count) {
            if ($count > 1) {
                [$taskPacketId, $runId] = $this->decodeRefKey($key);
                $duplicateObservedRefs[] = [
                    'task_packet_id' => $taskPacketId,
                    'run_id' => $runId,
                    'occurrences' => $count,
                ];
            }
        }

        $expectedKeys = [];
        $invalidExpectedCount = 0;
        foreach ($expectedRefs as $ref) {
            if (! $this->isValidRef($ref)) {
                $invalidExpectedCount++;

                continue;
            }
            $expectedKeys[$this->refKey($ref)] = true;
        }

        $missing = [];
        foreach ($expectedKeys as $key => $_) {
            if (! isset($observedCounts[$key])) {
                [$taskPacketId, $runId] = $this->decodeRefKey($key);
                $missing[] = [
                    'task_packet_id' => $taskPacketId,
                    'run_id' => $runId,
                    'reason' => 'expected_cost_event_missing',
                ];
            }
        }

        $unexpected = [];
        foreach ($observedCounts as $key => $_) {
            if (! isset($expectedKeys[$key])) {
                [$taskPacketId, $runId] = $this->decodeRefKey($key);
                $unexpected[] = [
                    'task_packet_id' => $taskPacketId,
                    'run_id' => $runId,
                    'reason' => 'unexpected_observed_cost_event',
                ];
            }
        }

        $hasGaps = $missing !== [] || $unexpected !== [];

        $payload = [
            'status' => $hasGaps ? 'reconciliation_dry_run_has_gaps' : 'reconciliation_dry_run_clear',
            'next_action' => $hasGaps ? 'repair_cost_event_manifest_before_import' : 'none_reconciliation_clear',
            'expected_ref_count' => count($expectedKeys),
            'observed_ref_count' => count($observedCounts),
            'invalid_observed_ref_count' => $invalidObservedCount,
            'invalid_expected_ref_count' => $invalidExpectedCount,
            'duplicate_observed_refs' => $duplicateObservedRefs,
            'duplicate_observed_ref_count' => count($duplicateObservedRefs),
            'missing_cost_event_refs' => $missing,
            'missing_count' => count($missing),
            'unexpected_observed_refs' => $unexpected,
            'unexpected_observed_ref_count' => count($unexpected),
            'import_allowed' => false,
            'cost_events_write_allowed' => false,
            'provider_billing_api_read_allowed' => false,
            'dry_run_only' => true,
        ];
        $payload['reconciliation_dry_run_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function isValidRef(array $value): bool
    {
        $taskPacketId = trim((string) ($value['task_packet_id'] ?? ''));
        $runId = trim((string) ($value['run_id'] ?? ''));

        return $taskPacketId !== '' && $runId !== '';
    }

    private function refKey(array $value): string
    {
        return json_encode([
            trim((string) ($value['task_packet_id'] ?? '')),
            trim((string) ($value['run_id'] ?? '')),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  string  $key  JSON-encoded ref key
     * @return array{string, string}
     */
    private function decodeRefKey(string $key): array
    {
        $parts = json_decode($key, true);
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    private function stableHash(array $payload): string
    {
        unset($payload['reconciliation_dry_run_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
