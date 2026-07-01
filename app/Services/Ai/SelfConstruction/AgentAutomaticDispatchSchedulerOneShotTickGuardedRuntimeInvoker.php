<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker
{
    public function __construct(
        private readonly AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter $writer,
        private readonly OneShotTickInputNormalizer $inputNormalizer = new OneShotTickInputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function invokeSignedOneShotSchedulerTick(array $input): array
    {
        $normalized = $this->normalize($input);

        $writerResult = $this->writer->executeOneShotSchedulerTickAfterReleasePreflight($normalized);

        return [
            'status' => 'guarded_runtime_invocation_completed',
            'invoked_writer' => true,
            'writer_invocation_count' => 1,
            'writer_result' => $writerResult,
            'created' => (bool) data_get($writerResult, 'created', false),
            'wakeup_claimed' => (bool) data_get($writerResult, 'wakeup_claimed', false),
            'dispatch_receipt_written' => (bool) data_get($writerResult, 'dispatch_receipt_written', false),
            'receipt_id' => data_get($writerResult, 'receipt_id'),
            'receipt_key' => data_get($writerResult, 'receipt_key'),
            'receipt_hash' => data_get($writerResult, 'receipt_hash'),
            'receipt_status' => data_get($writerResult, 'receipt_status'),
            'decision' => data_get($writerResult, 'decision'),
            'wakeup_item_id' => data_get($writerResult, 'wakeup_item_id'),
            'packet_id' => data_get($writerResult, 'packet_id'),
            'provider' => data_get($writerResult, 'provider'),
            'ledger_event_id' => data_get($writerResult, 'ledger_event_id'),
            'dispatch_receipt_use_allowed' => false,
            'mark_dispatch_receipt_used_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_release_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $input = $this->inputNormalizer->normalize(
            $input,
            [
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
            ],
            [
                'release_receipt_hash',
                'dispatch_envelope_hash',
                'source_release_preflight_hash',
                'source_mutating_writer_contract_hash',
                'source_mutating_writer_preflight_hash',
                'receipt_hash',
                'adapter_contract_hash',
            ],
        );

        CarbonImmutable::parse((string) $input['signed_at']);
        $expiresAt = CarbonImmutable::parse((string) $input['expires_at']);

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('expired_guarded_runtime_invocation_signature');
        }

        if (! is_array($input['payload'])) {
            throw new InvalidArgumentException('invalid_payload');
        }

        return $input;
    }
}
