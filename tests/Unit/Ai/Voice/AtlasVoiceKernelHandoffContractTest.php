<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceKernelHandoffContract;
use Tests\TestCase;

final class AtlasVoiceKernelHandoffContractTest extends TestCase
{
    public function test_contract_documents_handoff_without_kernel_execution(): void
    {
        $payload = app(AtlasVoiceKernelHandoffContract::class)->contract();

        $this->assertSame('atlas.voice_realtime.kernel_handoff_contract.v1', $payload['schema_version']);
        $this->assertSame('handoff_planning_only', $payload['mode']);
        $this->assertSame('kernel_handoff_contract_no_kernel_execution', $payload['authority']);
        $this->assertFalse(data_get($payload, 'guardrails.kernel_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.ledger_write_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.operation_envelope_creation_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.decision_receipt_issuance_enabled'));
        $this->assertSame('turn_decision_request', data_get($payload, 'callback_to_handoff.transcript_final'));
        $this->assertTrue(data_get($payload, 'handoff_requirements.turn_decision_request.operation_envelope_required'));
        $this->assertTrue(data_get($payload, 'handoff_requirements.turn_decision_request.decision_receipt_required'));
        $this->assertTrue(data_get($payload, 'handoff_requirements.runtime_callback_report.existing_decision_receipt_required'));
    }

    public function test_turn_event_prepares_decision_request_handoff(): void
    {
        $result = app(AtlasVoiceKernelHandoffContract::class)->prepareEvent($this->fixtures()['turn_event']);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('ready_for_kernel_handoff', $result['status']);
        $this->assertSame('turn_decision_request', data_get($result, 'handoff.handoff_kind'));
        $this->assertSame('handleTurn', data_get($result, 'handoff.kernel_method'));
        $this->assertSame('voice_session_handoff_001', data_get($result, 'handoff.session_id'));
        $this->assertSame('turn_handoff_001', data_get($result, 'handoff.turn_id'));
        $this->assertSame('programming', data_get($result, 'handoff.domain_hint'));
        $this->assertSame('programming.implementation', data_get($result, 'handoff.flow_hint'));
        $this->assertTrue(data_get($result, 'handoff.requirements.operation_envelope_required'));
        $this->assertTrue(data_get($result, 'handoff.requirements.decision_receipt_required'));
        $this->assertFalse(data_get($result, 'handoff.privacy.raw_audio_present'));
        $this->assertStringStartsWith('voice_realtime:turn_decision_request:transcript_final:voice_session_handoff_001:turn_handoff_001:', data_get($result, 'handoff.dedupe_key'));
    }

    public function test_turn_handoff_hash_and_dedupe_are_stable_across_payload_key_order(): void
    {
        $contract = app(AtlasVoiceKernelHandoffContract::class);
        $first = $contract->prepareEvent([
            'event_kind' => 'transcribed_turn',
            'session_id' => 'voice_session_handoff_stable',
            'turn_id' => 'turn_handoff_stable',
            'transcript' => 'preciso revisar AP-687',
            'domain_hint' => 'programming',
            'flow_hint' => 'programming.implementation',
        ]);
        $second = $contract->prepareEvent([
            'flow_hint' => 'programming.implementation',
            'domain_hint' => 'programming',
            'transcript' => 'preciso revisar AP-687',
            'turn_id' => 'turn_handoff_stable',
            'session_id' => 'voice_session_handoff_stable',
            'event_kind' => 'transcribed_turn',
        ]);

        $this->assertTrue($first['valid'], json_encode($first['errors']));
        $this->assertTrue($second['valid'], json_encode($second['errors']));
        $this->assertSame(data_get($first, 'handoff.canonical_event_hash'), data_get($second, 'handoff.canonical_event_hash'));
        $this->assertSame(data_get($first, 'handoff.dedupe_key'), data_get($second, 'handoff.dedupe_key'));
        $this->assertTrue(data_get($first, 'handoff.requirements.decision_receipt_required'));
    }

    public function test_runtime_callback_prepares_report_handoff_requiring_existing_receipt(): void
    {
        $result = app(AtlasVoiceKernelHandoffContract::class)->prepareEvent($this->fixtures()['callback_event']);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('runtime_callback_report', data_get($result, 'handoff.handoff_kind'));
        $this->assertSame('runtimeCallback', data_get($result, 'handoff.kernel_method'));
        $this->assertTrue(data_get($result, 'handoff.requirements.existing_decision_receipt_required'));
        $this->assertFalse(data_get($result, 'handoff.requirements.operation_envelope_required'));
        $this->assertSame('anthropic', data_get($result, 'handoff.payload.provider'));
        $this->assertArrayNotHasKey('response_text', data_get($result, 'handoff.payload'));
    }

    public function test_sequence_prepares_ordered_handoffs_after_normalization(): void
    {
        $result = app(AtlasVoiceKernelHandoffContract::class)->prepareSequence($this->fixtures()['sequence']);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('ready_for_kernel_handoff_sequence', $result['status']);
        $this->assertSame(5, $result['handoff_count']);
        $this->assertSame('session_start', data_get($result, 'handoffs.0.handoff_kind'));
        $this->assertSame('turn_decision_request', data_get($result, 'handoffs.1.handoff_kind'));
        $this->assertSame('runtime_callback_report', data_get($result, 'handoffs.2.handoff_kind'));
        $this->assertSame('runtime_callback_report', data_get($result, 'handoffs.3.handoff_kind'));
        $this->assertSame('session_end', data_get($result, 'handoffs.4.handoff_kind'));
        $this->assertSame('valid', data_get($result, 'normalization.sequence_validation.status'));
    }

    public function test_invalid_event_does_not_prepare_kernel_handoff(): void
    {
        $result = app(AtlasVoiceKernelHandoffContract::class)->prepareEvent($this->fixtures()['invalid_event']);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_event', $result['status']);
        $this->assertContains('forbidden_runtime_field:raw_audio', $result['errors']);
        $this->assertArrayNotHasKey('handoff', $result);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtures(): array
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Ai/voice-kernel-handoff-events.json'));

        $this->assertIsString($contents);

        $fixtures = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.kernel_handoff_fixtures.v1', $fixtures['schema_version']);

        return $fixtures;
    }
}
