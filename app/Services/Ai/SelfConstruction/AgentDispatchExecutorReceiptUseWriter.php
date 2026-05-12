<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentDispatchExecutorReceiptUseWriter
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
    public function markReceiptUsedAtomically(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable(self::DISPATCH_RECEIPTS_TABLE)) {
            throw new InvalidArgumentException('dispatch_receipts_table_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('receipt_hash', $normalized['receipt_hash'])
                ->lockForUpdate()
                ->first();

            if (! $receipt instanceof AtlasSelfConstructionAgentDispatchReceipt) {
                throw new InvalidArgumentException('dispatch_receipt_not_found');
            }

            $existingAttemptId = (string) data_get($receipt->payload, 'receipt_use.provider_start_attempt_id', '');

            if ($receipt->used_at !== null) {
                if ($existingAttemptId !== '' && $existingAttemptId === $normalized['provider_start_attempt_id']) {
                    return $this->result($receipt, idempotent: true, ledgerEventId: null);
                }

                throw new InvalidArgumentException('dispatch_receipt_already_used');
            }

            $this->assertReceiptCanBeUsed($receipt, $normalized);

            $payload = (array) ($receipt->payload ?? []);
            $payload['receipt_use'] = [
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'executor_contract_hash' => $normalized['executor_contract_hash'],
                'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'reason' => $normalized['reason'],
                'used_by_writer' => self::class,
                'used_before_provider_start' => true,
                'provider_start_side_effect_performed' => false,
                'marked_at' => CarbonImmutable::now()->toIso8601String(),
            ];

            $receipt->forceFill([
                'status' => 'used_pending_provider_start',
                'used_at' => CarbonImmutable::now(),
                'payload' => $payload,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_dispatch_executor_receipt.used',
                'receipt_key' => $receipt->receipt_key,
                'receipt_hash' => $receipt->receipt_hash,
                'packet_id' => $receipt->packet_id,
                'provider' => $receipt->provider,
                'provider_role' => $receipt->provider_role,
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'executor_contract_hash' => $normalized['executor_contract_hash'],
                'executor_release_authorization_hash' => $normalized['executor_release_authorization_hash'],
                'provider_start_side_effect_performed' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_dispatch_executor_receipt_use',
                'receipt_id' => $receipt->receipt_key,
                'correlation_id' => $receipt->receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-dispatch-executor-receipt-use-writer.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($receipt, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function normalize(array $input): array
    {
        $required = [
            'receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'provider_start_attempt_id',
            'actor',
            'session',
            'packet_id',
            'provider',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach (['receipt_hash', 'executor_contract_hash', 'executor_release_authorization_hash'] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        return [
            'receipt_hash' => (string) $input['receipt_hash'],
            'executor_contract_hash' => (string) $input['executor_contract_hash'],
            'executor_release_authorization_hash' => (string) $input['executor_release_authorization_hash'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'packet_id' => (string) $input['packet_id'],
            'provider' => (string) $input['provider'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,string>  $normalized
     */
    private function assertReceiptCanBeUsed(AtlasSelfConstructionAgentDispatchReceipt $receipt, array $normalized): void
    {
        if ($receipt->decision !== 'approve_dispatch_once') {
            throw new InvalidArgumentException('dispatch_receipt_decision_not_approved');
        }

        if ($receipt->status !== 'signed_pending_dispatch') {
            throw new InvalidArgumentException('dispatch_receipt_status_not_pending');
        }

        if ($receipt->expires_at !== null && $receipt->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('dispatch_receipt_expired');
        }

        if ((string) $receipt->packet_id !== $normalized['packet_id']) {
            throw new InvalidArgumentException('dispatch_receipt_packet_mismatch');
        }

        if ((string) $receipt->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('dispatch_receipt_provider_mismatch');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentDispatchReceipt $receipt,
        bool $idempotent,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'receipt_marked_used',
            'receipt_id' => (string) $receipt->id,
            'receipt_key' => $receipt->receipt_key,
            'receipt_hash' => $receipt->receipt_hash,
            'new_status' => $receipt->status,
            'used_at' => $receipt->used_at?->toIso8601String(),
            'idempotent' => $idempotent,
            'ledger_event_id' => $ledgerEventId,
            'receipt_use_condition_satisfied' => true,
            'provider_start_allowed_after_mark' => false,
            'dispatch_allowed' => false,
        ];
    }
}
