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

class AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter
{
    private const DISPATCH_RECEIPTS_TABLE = 'atlas_self_construction_agent_dispatch_receipts';

    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function persistSignedReleaseReceipt(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable(self::DISPATCH_RECEIPTS_TABLE)) {
            throw new InvalidArgumentException('dispatch_receipts_table_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $wakeup = AtlasSelfConstructionAgentWakeupItem::query()
                ->where('wakeup_key', $normalized['selected_wakeup_key'])
                ->lockForUpdate()
                ->first();

            if (! $wakeup instanceof AtlasSelfConstructionAgentWakeupItem) {
                throw new InvalidArgumentException('selected_wakeup_item_missing');
            }

            if ($wakeup->status !== 'queued') {
                throw new InvalidArgumentException('selected_wakeup_item_not_queued');
            }

            $existingByHash = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('receipt_hash', $normalized['receipt_hash'])
                ->first();

            if ($existingByHash instanceof AtlasSelfConstructionAgentDispatchReceipt) {
                return $this->result($existingByHash, created: false, ledgerEventId: null);
            }

            $duplicateKey = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('receipt_key', $normalized['receipt_key'])
                ->exists();

            if ($duplicateKey) {
                throw new InvalidArgumentException('duplicate_release_receipt_key');
            }

            $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()->create([
                'agent_run_id' => $wakeup->agent_run_id,
                'wakeup_item_id' => $wakeup->id,
                'receipt_key' => $normalized['receipt_key'],
                'packet_id' => $wakeup->packet_id ?? $normalized['packet_id'],
                'provider' => $wakeup->provider ?: $normalized['provider'],
                'provider_role' => $normalized['provider_role'],
                'decision' => $normalized['decision'],
                'status' => 'release_authorized_pending_one_shot_tick',
                'signed_by' => $normalized['signed_by'],
                'signed_at' => $normalized['signed_at'],
                'expires_at' => $normalized['expires_at'],
                'dispatch_envelope_hash' => $normalized['dispatch_envelope_hash'],
                'adapter_contract_hash' => null,
                'receipt_hash' => $normalized['receipt_hash'],
                'payload' => $normalized['payload'] + [
                    'source' => 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer',
                    'selected_wakeup_key' => $normalized['selected_wakeup_key'],
                    'source_release_template_hash' => $normalized['source_release_template_hash'],
                    'source_release_receipt_draft_hash' => $normalized['source_release_receipt_draft_hash'],
                    'source_validation_preflight_hash' => $normalized['source_validation_preflight_hash'],
                    'source_persistence_contract_hash' => $normalized['source_persistence_contract_hash'],
                    'source_persistence_writer_preflight_hash' => $normalized['source_persistence_writer_preflight_hash'],
                    'scope_hash' => $normalized['scope_hash'],
                    'claim_allowed_by_writer' => false,
                    'dispatch_receipt_write_allowed_by_writer' => false,
                    'provider_start_allowed_by_writer' => false,
                    'self_programming_allowed_by_writer' => false,
                ],
            ]);

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.automatic_dispatch_scheduler.one_shot_tick_release_receipt.persisted',
                'receipt_key' => $receipt->receipt_key,
                'receipt_hash' => $receipt->receipt_hash,
                'decision' => $receipt->decision,
                'status' => $receipt->status,
                'wakeup_key' => $wakeup->wakeup_key,
                'wakeup_item_id' => $wakeup->id,
                'packet_id' => $receipt->packet_id,
                'provider' => $receipt->provider,
                'source_persistence_writer_preflight_hash' => $normalized['source_persistence_writer_preflight_hash'],
                'claim_allowed' => false,
                'dispatch_receipt_write_allowed' => false,
                'provider_start_allowed' => false,
                'self_programming_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:automatic_dispatch_scheduler_one_shot_tick_release_receipt',
                'receipt_id' => $receipt->receipt_key,
                'correlation_id' => $receipt->receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'automatic-dispatch-scheduler-one-shot-tick-release-receipt-persistence-writer.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($receipt, created: true, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'decision',
            'signed_by',
            'signed_at',
            'expires_at',
            'selected_wakeup_key',
            'dispatch_envelope_hash',
            'scope_hash',
            'source_release_template_hash',
            'source_release_receipt_draft_hash',
            'source_validation_preflight_hash',
            'source_persistence_contract_hash',
            'source_persistence_writer_preflight_hash',
            'receipt_hash',
            'payload',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        if ((string) $input['decision'] !== 'approve_scheduler_claim_and_receipt_once') {
            throw new InvalidArgumentException('invalid_decision');
        }

        foreach ([
            'dispatch_envelope_hash',
            'scope_hash',
            'source_release_template_hash',
            'source_release_receipt_draft_hash',
            'source_validation_preflight_hash',
            'source_persistence_contract_hash',
            'source_persistence_writer_preflight_hash',
            'receipt_hash',
        ] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        $signedAt = CarbonImmutable::parse((string) $input['signed_at']);
        $expiresAt = CarbonImmutable::parse((string) $input['expires_at']);

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('expired_signed_receipt');
        }

        $receiptKey = trim((string) ($input['receipt_key'] ?? ''));

        if ($receiptKey === '') {
            $receiptKey = 'SCHEDULER-ONE-SHOT-RELEASE-'.strtoupper(substr($input['receipt_hash'], 0, 24));
        }

        return [
            'receipt_key' => $receiptKey,
            'packet_id' => $this->nullableString($input['packet_id'] ?? null),
            'provider' => $this->nullableString($input['provider'] ?? null) ?? 'scheduler',
            'provider_role' => $this->nullableString($input['provider_role'] ?? null),
            'decision' => (string) $input['decision'],
            'signed_by' => (string) $input['signed_by'],
            'signed_at' => $signedAt,
            'expires_at' => $expiresAt,
            'selected_wakeup_key' => (string) $input['selected_wakeup_key'],
            'dispatch_envelope_hash' => $input['dispatch_envelope_hash'],
            'scope_hash' => $input['scope_hash'],
            'source_release_template_hash' => $input['source_release_template_hash'],
            'source_release_receipt_draft_hash' => $input['source_release_receipt_draft_hash'],
            'source_validation_preflight_hash' => $input['source_validation_preflight_hash'],
            'source_persistence_contract_hash' => $input['source_persistence_contract_hash'],
            'source_persistence_writer_preflight_hash' => $input['source_persistence_writer_preflight_hash'],
            'receipt_hash' => $input['receipt_hash'],
            'payload' => (array) $input['payload'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentDispatchReceipt $receipt,
        bool $created,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'persisted',
            'created' => $created,
            'receipt_id' => $receipt->id,
            'receipt_key' => $receipt->receipt_key,
            'decision' => $receipt->decision,
            'receipt_status' => $receipt->status,
            'receipt_hash' => $receipt->receipt_hash,
            'wakeup_item_id' => $receipt->wakeup_item_id,
            'packet_id' => $receipt->packet_id,
            'provider' => $receipt->provider,
            'ledger_event_id' => $ledgerEventId,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
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
