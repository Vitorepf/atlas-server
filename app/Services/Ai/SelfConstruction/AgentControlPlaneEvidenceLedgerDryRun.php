<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Plans which receipts and evidence events a runtime pilot WOULD register,
 * without writing anything to the evidence ledger. Output is a deterministic
 * list of planned receipts, evidence events and supporting hashes.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneEvidenceLedgerDryRun
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_evidence_ledger_dry_run.v1';

    public const MODE = 'read_only_agent_control_plane_evidence_ledger_dry_run';

    public const REQUIRED_RECEIPTS = [
        'task_packet_created',
        'claim_lease_simulated',
        'scope_lock_planned',
        'continuation_summary_planned',
        'cost_import_planned',
        'work_product_manifest_planned',
        'runtime_pilot_completed',
    ];

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $scopeLock
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $taskPacket, array $scopeLock = [], array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $packetStatus = (string) ($taskPacket['status'] ?? 'unknown');
        $additional = (array) ($options['additional_receipts'] ?? []);

        $receipts = array_values(array_unique(array_merge(self::REQUIRED_RECEIPTS, $additional)));

        $plannedReceipts = [];
        foreach ($receipts as $kind) {
            $plannedReceipts[] = [
                'receipt_kind' => (string) $kind,
                'task_packet_id' => $packetId,
                'receipt_status' => 'planned',
                'persist_allowed' => false,
                'runtime_enabled' => false,
            ];
        }

        $evidenceEvents = [
            [
                'event_kind' => 'scope_lock_planning_recorded',
                'scope_lock_plan_hash' => (string) ($scopeLock['scope_lock_plan_hash'] ?? ''),
                'persist_allowed' => false,
                'runtime_enabled' => false,
            ],
            [
                'event_kind' => 'task_packet_acceptance_acknowledged',
                'acceptance_hash' => (string) ($taskPacket['acceptance_hash'] ?? ''),
                'persist_allowed' => false,
                'runtime_enabled' => false,
            ],
            [
                'event_kind' => 'pilot_completion_acknowledged',
                'persist_allowed' => false,
                'runtime_enabled' => false,
            ],
        ];

        $blockingReasons = [];
        if ($packetStatus !== 'planned') {
            $blockingReasons[] = 'task_packet_not_planned';
        }
        if ((array) data_get($scopeLock, 'blocking_reasons', []) !== []) {
            $blockingReasons[] = 'scope_lock_blocked';
        }

        $status = $blockingReasons === [] ? 'planned' : 'planned_blocked';

        $sanitizedReceipts = array_map(static function (array $r): array {
            unset($r['task_packet_id']);

            return $r;
        }, $plannedReceipts);
        $evidenceHash = $this->stableHash([
            'receipts' => $sanitizedReceipts,
            'events' => $evidenceEvents,
        ]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'evidence_plan_id' => (string) Str::uuid(),
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'planned_receipts' => $plannedReceipts,
            'planned_evidence_events' => $evidenceEvents,
            'receipt_count' => count($plannedReceipts),
            'event_count' => count($evidenceEvents),
            'evidence_hash' => $evidenceHash,
            'blocking_reasons' => $blockingReasons,
            'ledger_write_allowed' => false,
            'dry_run_only' => true,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'evidence_ledger_dry_run_does_not_start_codex',
                'evidence_ledger_dry_run_does_not_call_codex_cli_or_app',
                'evidence_ledger_dry_run_does_not_spawn_subprocess',
                'evidence_ledger_dry_run_does_not_invoke_adapter',
                'evidence_ledger_dry_run_does_not_call_provider',
                'evidence_ledger_dry_run_does_not_dispatch_work',
                'evidence_ledger_dry_run_does_not_spend_tokens',
                'evidence_ledger_dry_run_does_not_enable_self_programming',
                'evidence_ledger_dry_run_does_not_write_ledger',
                'evidence_ledger_dry_run_does_not_persist_receipts',
                'evidence_ledger_dry_run_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Planned %d receipts and %d evidence events for task packet %s (status %s).',
                count($plannedReceipts),
                count($evidenceEvents),
                $packetId,
                $status,
            ),
        ];

        $payload['evidence_plan_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['evidence_plan_id'], $clone['generated_at'], $clone['evidence_plan_hash'], $clone['human_summary'], $clone['task_packet_id']);
        // Strip task_packet_id from per-receipt entries (random across runs).
        if (isset($clone['planned_receipts']) && is_array($clone['planned_receipts'])) {
            $clone['planned_receipts'] = array_map(static function ($r) {
                if (is_array($r)) {
                    unset($r['task_packet_id']);
                }

                return $r;
            }, $clone['planned_receipts']);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        return ReadinessHash::ksortRecursive($value);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
