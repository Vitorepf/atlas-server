<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter
{
    private const WAKEUP_ITEMS_TABLE = 'atlas_self_construction_agent_wakeup_items';

    private const DISPATCH_RECEIPTS_TABLE = 'atlas_self_construction_agent_dispatch_receipts';

    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function executeOneShotSchedulerTickAfterReleasePreflight(array $input): array
    {
        $normalized = $this->normalize($input);

        $this->assertStorageReady();

        $existingByHash = AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('receipt_hash', $normalized['receipt_hash'])
            ->where('decision', 'approve_dispatch_once')
            ->where('status', 'signed_pending_dispatch')
            ->first();

        if ($existingByHash instanceof AtlasSelfConstructionAgentDispatchReceipt) {
            return $this->result($existingByHash, created: false, wakeupClaimed: false, ledgerEventId: null);
        }

        $duplicateKey = AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('receipt_key', $normalized['receipt_key'])
            ->exists();

        if ($duplicateKey) {
            throw new InvalidArgumentException('duplicate_dispatch_receipt_key');
        }

        return DB::transaction(function () use ($normalized): array {
            $readinessOptions = $this->readinessOptions($normalized);
            $releasePreflightPayload = $this->readiness->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight($readinessOptions);
            $releasePreflight = (array) data_get($releasePreflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight', []);

            if (data_get($releasePreflightPayload, 'status') !== 'one_shot_tick_mutating_writer_release_preflight_ready') {
                throw new InvalidArgumentException('release_preflight_not_ready');
            }

            $selectedWakeupKey = (string) data_get($releasePreflight, 'selected_candidate.selected_wakeup_key', '');

            if ($selectedWakeupKey === '' || $selectedWakeupKey !== $normalized['selected_wakeup_key']) {
                throw new InvalidArgumentException('selected_wakeup_key_mismatch');
            }

            $releaseReceipt = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('receipt_hash', $normalized['release_receipt_hash'])
                ->where('decision', 'approve_scheduler_claim_and_receipt_once')
                ->where('status', 'release_authorized_pending_one_shot_tick')
                ->lockForUpdate()
                ->first();

            if (! $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt) {
                throw new InvalidArgumentException('release_receipt_missing');
            }

            if ($releaseReceipt->expires_at === null || $releaseReceipt->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new InvalidArgumentException('release_receipt_expired');
            }

            if ($releaseReceipt->dispatch_envelope_hash !== $normalized['dispatch_envelope_hash']) {
                throw new InvalidArgumentException('dispatch_envelope_hash_mismatch');
            }

            $releasePayload = (array) $releaseReceipt->payload;

            if (data_get($releasePayload, 'provider_start_allowed_by_writer') !== false) {
                throw new InvalidArgumentException('release_receipt_does_not_forbid_provider_start');
            }

            if (data_get($releasePayload, 'dispatch_receipt_write_allowed_by_writer') !== false) {
                throw new InvalidArgumentException('release_receipt_does_not_prove_prior_dispatch_write_forbidden');
            }

            if (data_get($releasePayload, 'self_programming_allowed_by_writer') !== false) {
                throw new InvalidArgumentException('release_receipt_does_not_forbid_self_programming');
            }

            $wakeup = AtlasSelfConstructionAgentWakeupItem::query()
                ->where('wakeup_key', $normalized['selected_wakeup_key'])
                ->lockForUpdate()
                ->first();

            if (! $wakeup instanceof AtlasSelfConstructionAgentWakeupItem) {
                throw new InvalidArgumentException('selected_wakeup_item_missing');
            }

            if ($wakeup->status !== 'queued' || $wakeup->claimed_at !== null) {
                throw new InvalidArgumentException('selected_wakeup_item_not_claimable');
            }

            $now = CarbonImmutable::now();
            $wakeup->forceFill([
                'status' => 'claimed',
                'claimed_at' => $now,
                'payload' => array_merge((array) $wakeup->payload, [
                    'claimed_by' => [
                        'source' => 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer',
                        'signed_by' => $normalized['signed_by'],
                        'receipt_key' => $normalized['receipt_key'],
                        'receipt_hash' => $normalized['receipt_hash'],
                        'release_receipt_hash' => $normalized['release_receipt_hash'],
                        'claimed_at' => $now->toIso8601String(),
                    ],
                ]),
            ])->save();

            $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()->create([
                'agent_run_id' => $wakeup->agent_run_id,
                'wakeup_item_id' => $wakeup->id,
                'receipt_key' => $normalized['receipt_key'],
                'packet_id' => $wakeup->packet_id ?? $releaseReceipt->packet_id ?? $normalized['packet_id'],
                'provider' => $wakeup->provider ?: $releaseReceipt->provider ?: $normalized['provider'],
                'provider_role' => $normalized['provider_role'] ?? $releaseReceipt->provider_role,
                'decision' => 'approve_dispatch_once',
                'status' => 'signed_pending_dispatch',
                'signed_by' => $normalized['signed_by'],
                'signed_at' => $normalized['signed_at'],
                'expires_at' => $normalized['expires_at'],
                'dispatch_envelope_hash' => $normalized['dispatch_envelope_hash'],
                'adapter_contract_hash' => $normalized['adapter_contract_hash'],
                'receipt_hash' => $normalized['receipt_hash'],
                'payload' => $normalized['payload'] + [
                    'source' => 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer',
                    'selected_wakeup_key' => $normalized['selected_wakeup_key'],
                    'release_receipt_key' => $releaseReceipt->receipt_key,
                    'release_receipt_hash' => $releaseReceipt->receipt_hash,
                    'source_release_preflight_hash' => $normalized['source_release_preflight_hash'],
                    'source_mutating_writer_contract_hash' => $normalized['source_mutating_writer_contract_hash'],
                    'source_mutating_writer_preflight_hash' => $normalized['source_mutating_writer_preflight_hash'],
                    'dispatch_receipt_use_allowed_by_writer' => false,
                    'provider_start_allowed_by_writer' => false,
                    'adapter_invocation_allowed_by_writer' => false,
                    'token_spend_allowed_by_writer' => false,
                    'self_programming_allowed_by_writer' => false,
                ],
            ]);

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.automatic_dispatch_scheduler.one_shot_tick.claimed_and_dispatch_receipt_written',
                'receipt_key' => $receipt->receipt_key,
                'receipt_hash' => $receipt->receipt_hash,
                'release_receipt_key' => $releaseReceipt->receipt_key,
                'release_receipt_hash' => $releaseReceipt->receipt_hash,
                'decision' => $receipt->decision,
                'status' => $receipt->status,
                'wakeup_key' => $wakeup->wakeup_key,
                'wakeup_item_id' => $wakeup->id,
                'packet_id' => $receipt->packet_id,
                'provider' => $receipt->provider,
                'source_release_preflight_hash' => $normalized['source_release_preflight_hash'],
                'source_mutating_writer_contract_hash' => $normalized['source_mutating_writer_contract_hash'],
                'source_mutating_writer_preflight_hash' => $normalized['source_mutating_writer_preflight_hash'],
                'wakeup_claimed' => true,
                'dispatch_receipt_written' => true,
                'dispatch_receipt_use_allowed' => false,
                'provider_start_allowed' => false,
                'adapter_invocation_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:automatic_dispatch_scheduler_one_shot_tick_mutating_writer',
                'receipt_id' => $receipt->receipt_key,
                'correlation_id' => $receipt->receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'automatic-dispatch-scheduler-one-shot-tick-mutating-writer.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($receipt, created: true, wakeupClaimed: true, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'release_receipt_hash',
            'selected_wakeup_key',
            'dispatch_envelope_hash',
            'source_release_preflight_hash',
            'source_mutating_writer_contract_hash',
            'source_mutating_writer_preflight_hash',
            'receipt_hash',
            'signed_by',
            'signed_at',
            'expires_at',
            'payload',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach ([
            'release_receipt_hash',
            'dispatch_envelope_hash',
            'source_release_preflight_hash',
            'source_mutating_writer_contract_hash',
            'source_mutating_writer_preflight_hash',
            'receipt_hash',
            'adapter_contract_hash',
        ] as $hashField) {
            if (! Arr::has($input, $hashField) || $input[$hashField] === null || $input[$hashField] === '') {
                continue;
            }

            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        $signedAt = CarbonImmutable::parse((string) $input['signed_at']);
        $expiresAt = CarbonImmutable::parse((string) $input['expires_at']);

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('expired_dispatch_receipt_signature');
        }

        $receiptKey = trim((string) ($input['receipt_key'] ?? ''));

        if ($receiptKey === '') {
            $receiptKey = 'SCHEDULER-ONE-SHOT-DISPATCH-'.strtoupper(substr($input['receipt_hash'], 0, 24));
        }

        return [
            'receipt_key' => $receiptKey,
            'packet_id' => $this->nullableString($input['packet_id'] ?? null),
            'provider' => $this->nullableString($input['provider'] ?? null) ?? 'scheduler',
            'provider_role' => $this->nullableString($input['provider_role'] ?? null),
            'signed_by' => (string) $input['signed_by'],
            'signed_at' => $signedAt,
            'expires_at' => $expiresAt,
            'release_receipt_hash' => $input['release_receipt_hash'],
            'selected_wakeup_key' => (string) $input['selected_wakeup_key'],
            'dispatch_envelope_hash' => $input['dispatch_envelope_hash'],
            'source_release_preflight_hash' => $input['source_release_preflight_hash'],
            'source_mutating_writer_contract_hash' => $input['source_mutating_writer_contract_hash'],
            'source_mutating_writer_preflight_hash' => $input['source_mutating_writer_preflight_hash'],
            'adapter_contract_hash' => $input['adapter_contract_hash'] ?? null,
            'receipt_hash' => $input['receipt_hash'],
            'payload' => (array) $input['payload'],
        ];
    }

    private function assertStorageReady(): void
    {
        if (! Schema::hasTable(self::WAKEUP_ITEMS_TABLE)) {
            throw new InvalidArgumentException('wakeup_items_table_missing');
        }

        if (! Schema::hasTable(self::DISPATCH_RECEIPTS_TABLE)) {
            throw new InvalidArgumentException('dispatch_receipts_table_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function readinessOptions(array $normalized): array
    {
        return [
            'workspace' => null,
            'target' => null,
            'actor' => $normalized['signed_by'],
            'session' => null,
            'packet' => $normalized['packet_id'],
            'receipt_hash' => $normalized['release_receipt_hash'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentDispatchReceipt $receipt,
        bool $created,
        bool $wakeupClaimed,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'signed_pending_dispatch_written',
            'created' => $created,
            'wakeup_claimed' => $wakeupClaimed,
            'dispatch_receipt_written' => $created,
            'receipt_id' => $receipt->id,
            'receipt_key' => $receipt->receipt_key,
            'decision' => $receipt->decision,
            'receipt_status' => $receipt->status,
            'receipt_hash' => $receipt->receipt_hash,
            'wakeup_item_id' => $receipt->wakeup_item_id,
            'packet_id' => $receipt->packet_id,
            'provider' => $receipt->provider,
            'ledger_event_id' => $ledgerEventId,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
