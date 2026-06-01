<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

/**
 * Governed bidirectional gateway boundary for the Hermes Executive Runtime.
 *
 * Unlike the output-parsing adapters (Memory/Schedule/Procedure), this adapter
 * is INGRESS-driven: it normalizes an external-channel message that Hermes
 * carried in from a chat surface (`job.payload.hermes.gateway_envelope`) into a
 * sealed `atlas.hermes.gateway_envelope.v1`, then GATES delivery of an
 * ATLS-authored reply. Hermes is transport only — `delivery_authority` is
 * always Atlas and `hermes_gateway_can_decide` is always false. Delivery is
 * fail-closed: it is allowed now ONLY when the operator policy is the Atlas
 * adapter, Atlas explicitly authorised the gateway, and an ATLS trace is
 * present. The external user reference and message body are never stored raw —
 * only sha256 digests reach the receipt.
 */
class HermesGatewayAdapter
{
    use HermesAdapterReceipt;

    /**
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function process(AiJob $job, array $resultPacket, array $mission, array $invocation, string $gatewayPolicy, bool $gatewayAllowed): array
    {
        $raw = data_get($job->payload, 'hermes.gateway_envelope', []);
        $raw = is_array($raw) ? $raw : [];

        $tracePresent = is_string($job->trace_id) && trim($job->trace_id) !== '';

        $receipt = [
            'schema_version' => 'atlas.hermes.gateway_adapter_receipt.v1',
            'adapter' => 'hermes_gateway_adapter',
            'gateway_policy' => $gatewayPolicy,
            'delivery_authority' => 'atlas',
            'hermes_gateway_can_deliver' => true,
            'hermes_gateway_can_decide' => false,
            'gateway_allowed' => $gatewayAllowed,
            'delivery_allowed_now' => false,
            'atls_trace_id' => $tracePresent ? $job->trace_id : null,
            'atls_trace_present' => $tracePresent,
            'candidate_count' => 0,
            'eligible_candidate_count' => 0,
            'delivered_count' => 0,
            'blocked_count' => 0,
            'skipped_count' => 0,
            'blocked_reason' => null,
            'skipped_candidates' => [],
            'envelope' => null,
            'status' => 'no_gateway_ingress',
        ];

        if ($raw === []) {
            return $this->withReceiptHash($receipt);
        }

        if ($gatewayPolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = 1;
            $receipt['skipped_candidates'] = [[
                'candidate_id' => $this->envelopeId($job, $this->messageHash($this->string($raw['message'] ?? null, 4000))),
                'reason' => 'gateway_policy_not_atlas_adapter',
            ]];

            return $this->withReceiptHash($receipt);
        }

        $envelope = $this->buildEnvelope($job, $raw);
        $receipt['envelope'] = $envelope;
        $receipt['candidate_count'] = 1;
        $receipt['eligible_candidate_count'] = 1;

        if (! $gatewayAllowed) {
            $receipt['status'] = 'ingress_normalized_delivery_blocked';
            $receipt['blocked_count'] = 1;
            $receipt['blocked_reason'] = 'gateway_allowed_false';

            return $this->withReceiptHash($receipt);
        }

        if (! $tracePresent) {
            $receipt['status'] = 'ingress_normalized_delivery_blocked';
            $receipt['blocked_count'] = 1;
            $receipt['blocked_reason'] = 'atls_trace_missing';

            return $this->withReceiptHash($receipt);
        }

        $receipt['status'] = 'ingress_normalized_delivery_allowed';
        $receipt['delivery_allowed_now'] = true;
        $receipt['delivered_count'] = 0;

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function buildEnvelope(AiJob $job, array $raw): array
    {
        $messageHash = $this->messageHash($this->string($raw['message'] ?? null, 4000));
        $tracePresent = is_string($job->trace_id) && trim($job->trace_id) !== '';

        return [
            'schema_version' => 'atlas.hermes.gateway_envelope.v1',
            'envelope_id' => $this->envelopeId($job, $messageHash),
            'channel' => $this->channel($raw['channel'] ?? null),
            'external_user_ref_hash' => $this->externalUserRefHash($raw['external_user_ref'] ?? null),
            'message_hash' => $messageHash,
            'attachment_refs' => $this->attachmentRefs($raw['attachments'] ?? null),
            'received_at' => now()->toJSON(),
            'delivery_authority' => 'atlas',
            'hermes_gateway_can_deliver' => true,
            'hermes_gateway_can_decide' => false,
            'atls_trace_id' => $tracePresent ? $job->trace_id : null,
        ];
    }

    private function channel(mixed $value): string
    {
        $channel = $this->string($value, 40);

        return in_array($channel, ['telegram', 'discord', 'api', 'other'], true) ? $channel : 'other';
    }

    private function externalUserRefHash(mixed $ref): ?string
    {
        if (! is_string($ref) && ! is_numeric($ref)) {
            return null;
        }

        $ref = trim((string) $ref);

        return $ref === '' ? null : hash('sha256', $ref);
    }

    private function messageHash(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return hash('sha256', AtlasSecurity::redactString($message));
    }

    /**
     * @return array<int,array<string,?string>>
     */
    private function attachmentRefs(mixed $value): array
    {
        $items = is_array($value) ? array_values($value) : [];

        return collect(array_slice($items, 0, 12))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'ref_hash' => $this->attachmentRefHash($item),
                'mime' => $this->string($item['mime'] ?? null, 120),
            ])
            ->filter(fn (array $item): bool => $item['ref_hash'] !== null)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function attachmentRefHash(array $item): ?string
    {
        $ref = $this->string($item['ref'] ?? $item['url'] ?? $item['path'] ?? null, 4000);

        return $ref === null ? null : hash('sha256', $ref);
    }

    private function envelopeId(AiJob $job, ?string $messageHash): string
    {
        $trace = is_string($job->trace_id) && trim($job->trace_id) !== '' ? trim($job->trace_id) : 'no_trace';
        $channel = $this->channel(data_get($job->payload, 'hermes.gateway_envelope.channel'));
        $seed = $trace.'|'.($messageHash ?? 'no_message').'|'.$channel;

        return 'hermes_gateway_envelope_'.substr(hash('sha256', $seed), 0, 24);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
