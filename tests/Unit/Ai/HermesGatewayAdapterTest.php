<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesGatewayAdapter;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Tests\TestCase;

class HermesGatewayAdapterTest extends TestCase
{
    private const SECRET = 'sk-SECRETKEY123456';

    private const RAW_USER_REF = '@operator123';

    private function adapter(): HermesGatewayAdapter
    {
        return app(HermesGatewayAdapter::class);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function job(array $envelope, ?string $traceId = null): AiJob
    {
        return new AiJob([
            'trace_id' => $traceId ?? (string) Str::uuid(),
            'payload' => [
                'hermes' => [
                    'gateway_envelope' => $envelope,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function process(array $envelope, string $gatewayPolicy, bool $gatewayAllowed, ?string $traceId = null, bool $jobHasEnvelope = true): array
    {
        if (! $jobHasEnvelope) {
            $job = new AiJob([
                'trace_id' => $traceId ?? (string) Str::uuid(),
                'payload' => ['hermes' => []],
            ]);
        } else {
            $job = $this->job($envelope, $traceId);
        }

        return $this->adapter()->process(
            $job,
            ['result_id' => 'r', 'result_hash' => 'h', 'gateway' => ['delivery_authority' => 'atlas']],
            ['mission_id' => 'm', 'mission_hash' => 'mh'],
            ['command' => ['hermes']],
            $gatewayPolicy,
            $gatewayAllowed,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function richEnvelope(): array
    {
        return [
            'channel' => 'telegram',
            'external_user_ref' => self::RAW_USER_REF,
            'message' => 'hello with '.self::SECRET,
            'attachments' => [
                ['url' => '/tmp/x.png', 'mime' => 'image/png'],
            ],
        ];
    }

    public function test_no_gateway_ingress_yields_no_gateway_ingress_status_and_blocked_delivery(): void
    {
        $receipt = $this->process([], 'atlas_adapter', true, jobHasEnvelope: false);

        $this->assertSame('no_gateway_ingress', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'delivery_allowed_now'));
        $this->assertNull(data_get($receipt, 'envelope'));
        $this->assertSame(0, data_get($receipt, 'candidate_count'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_gateway_can_decide'));
        $this->assertSame('atlas', data_get($receipt, 'delivery_authority'));
    }

    public function test_skipped_by_policy_when_gateway_policy_not_atlas_adapter(): void
    {
        $receipt = $this->process($this->richEnvelope(), 'atlas_canonical', true);

        $this->assertSame('skipped_by_policy', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'delivery_allowed_now'));
        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertNull(data_get($receipt, 'envelope'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_gateway_can_decide'));
    }

    public function test_ingress_envelope_is_normalized_and_sealed(): void
    {
        $traceId = (string) Str::uuid();
        $receipt = $this->process($this->richEnvelope(), 'atlas_adapter', true, $traceId);

        $this->assertSame('atlas.hermes.gateway_envelope.v1', data_get($receipt, 'envelope.schema_version'));
        $this->assertSame('telegram', data_get($receipt, 'envelope.channel'));

        $expectedRefHash = hash('sha256', self::RAW_USER_REF);
        $this->assertSame($expectedRefHash, data_get($receipt, 'envelope.external_user_ref_hash'));
        $this->assertNotSame(self::RAW_USER_REF, data_get($receipt, 'envelope.external_user_ref_hash'));

        $expectedMsgHash = hash('sha256', AtlasSecurity::redactString('hello with '.self::SECRET));
        $this->assertSame($expectedMsgHash, data_get($receipt, 'envelope.message_hash'));

        $this->assertSame('atlas', data_get($receipt, 'envelope.delivery_authority'));
        $this->assertTrue((bool) data_get($receipt, 'envelope.hermes_gateway_can_deliver'));
        $this->assertFalse((bool) data_get($receipt, 'envelope.hermes_gateway_can_decide'));
        $this->assertSame($traceId, data_get($receipt, 'envelope.atls_trace_id'));
    }

    public function test_unknown_channel_falls_back_to_other(): void
    {
        $envelope = $this->richEnvelope();
        $envelope['channel'] = 'whatsapp';

        $receipt = $this->process($envelope, 'atlas_adapter', true);

        $this->assertSame('other', data_get($receipt, 'envelope.channel'));
    }

    public function test_gateway_allowed_false_blocks_delivery(): void
    {
        $receipt = $this->process($this->richEnvelope(), 'atlas_adapter', false);

        $this->assertSame('ingress_normalized_delivery_blocked', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'delivery_allowed_now'));
        $this->assertSame('gateway_allowed_false', data_get($receipt, 'blocked_reason'));
        $this->assertSame(1, data_get($receipt, 'blocked_count'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_gateway_can_decide'));
        $this->assertNotNull(data_get($receipt, 'envelope'));
    }

    public function test_gateway_allowed_true_permits_delivery_not_decision(): void
    {
        $receipt = $this->process($this->richEnvelope(), 'atlas_adapter', true);

        $this->assertSame('ingress_normalized_delivery_allowed', data_get($receipt, 'status'));
        $this->assertTrue((bool) data_get($receipt, 'delivery_allowed_now'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_gateway_can_decide'));
        $this->assertSame('atlas', data_get($receipt, 'delivery_authority'));
        $this->assertNull(data_get($receipt, 'blocked_reason'));
    }

    public function test_missing_atls_trace_blocks_delivery_even_when_allowed(): void
    {
        $job = new AiJob([
            'payload' => [
                'hermes' => [
                    'gateway_envelope' => $this->richEnvelope(),
                ],
            ],
        ]);

        $receipt = $this->adapter()->process(
            $job,
            ['result_id' => 'r', 'result_hash' => 'h', 'gateway' => ['delivery_authority' => 'atlas']],
            ['mission_id' => 'm', 'mission_hash' => 'mh'],
            ['command' => ['hermes']],
            'atlas_adapter',
            true,
        );

        $this->assertSame('ingress_normalized_delivery_blocked', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'delivery_allowed_now'));
        $this->assertFalse((bool) data_get($receipt, 'atls_trace_present'));
        $this->assertNull(data_get($receipt, 'atls_trace_id'));
        $this->assertSame('atls_trace_missing', data_get($receipt, 'blocked_reason'));
    }

    public function test_external_user_ref_and_message_are_never_stored_raw(): void
    {
        $receipt = $this->process($this->richEnvelope(), 'atlas_adapter', true);

        $json = json_encode($receipt);

        $this->assertIsString($json);
        $this->assertStringNotContainsString(self::RAW_USER_REF, $json);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('/tmp/x.png', $json);
    }

    public function test_receipt_hash_is_deterministic_and_sealed(): void
    {
        $envelope = $this->richEnvelope();
        $traceId = (string) Str::uuid();

        $first = $this->process($envelope, 'skipped_policy', false, $traceId);
        $second = $this->process($envelope, 'skipped_policy', false, $traceId);

        $this->assertSame('atlas.hermes.gateway_adapter_receipt.v1', data_get($first, 'schema_version'));
        $this->assertSame('hermes_gateway_adapter', data_get($first, 'adapter'));
        $this->assertNotEmpty(data_get($first, 'receipt_hash'));
        $this->assertSame(data_get($first, 'receipt_hash'), data_get($second, 'receipt_hash'));
    }
}
