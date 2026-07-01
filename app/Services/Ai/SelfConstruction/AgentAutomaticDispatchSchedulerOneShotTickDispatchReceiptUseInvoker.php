<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;

class AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker
{
    public function __construct(
        private readonly AgentDispatchExecutorReceiptUseWriter $receiptUseWriter,
        private readonly OneShotTickInputNormalizer $inputNormalizer = new OneShotTickInputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function markSignedDispatchReceiptUsed(array $input): array
    {
        $normalized = $this->normalize($input);

        $receiptUseResult = $this->receiptUseWriter->markReceiptUsedAtomically($normalized);

        return [
            'status' => 'one_shot_scheduler_dispatch_receipt_used',
            'receipt_use_invoked' => true,
            'receipt_use_writer_invocation_count' => 1,
            'receipt_use_result' => $receiptUseResult,
            'receipt_id' => data_get($receiptUseResult, 'receipt_id'),
            'receipt_key' => data_get($receiptUseResult, 'receipt_key'),
            'receipt_hash' => data_get($receiptUseResult, 'receipt_hash'),
            'new_status' => data_get($receiptUseResult, 'new_status'),
            'used_at' => data_get($receiptUseResult, 'used_at'),
            'idempotent' => (bool) data_get($receiptUseResult, 'idempotent', false),
            'ledger_event_id' => data_get($receiptUseResult, 'ledger_event_id'),
            'receipt_use_condition_satisfied' => (bool) data_get($receiptUseResult, 'receipt_use_condition_satisfied', false),
            'dispatch_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_start_driver_release_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
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

        $input = $this->inputNormalizer->normalize(
            $input,
            $required,
            ['receipt_hash', 'executor_contract_hash', 'executor_release_authorization_hash'],
        );

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
}
