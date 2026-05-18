<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasAiVoxControllerTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        // V0 must work without atlas_ledger_events present (response-first).
        Schema::dropIfExists('atlas_ledger_events');
    }

    public function test_health_advertises_v0_dictation_only_and_paused_voice_realtime(): void
    {
        $this->getJson('/ai/vox/health', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxSchema::HEALTH)
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('mode', 'dictation_only')
            ->assertJsonPath('voice_realtime_status', 'paused_until_v6')
            ->assertJsonPath('supports.dictation', true)
            ->assertJsonPath('supports.prompt_polish', false)
            ->assertJsonPath('supports.intent_compile', false)
            ->assertJsonPath('supports.governed_execute', false)
            ->assertJsonPath('kernel_guarantees.provider_call', false)
            ->assertJsonPath('kernel_guarantees.tool_call', false)
            ->assertJsonPath('kernel_guarantees.raw_audio_accepted', false)
            ->assertJsonPath('kernel_guarantees.external_side_effect', false)
            ->assertJsonPath('laws.0', '0')
            ->assertJsonPath('laws.1', '0.5')
            ->assertJsonPath('laws.2', '0.75')
            ->assertJsonPath('laws.3', '0.9');
    }

    public function test_intent_dictation_returns_intent_packet_and_r0_receipt_with_safe_flags(): void
    {
        $sessionId = (string) Str::uuid();
        $transcriptId = (string) Str::uuid();

        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'session_id' => $sessionId,
            'transcript_id' => $transcriptId,
            'text' => 'manda o Codex olhar esse módulo do voice sem mexer',
        ]), $this->headers);

        $response->assertOk()
            ->assertJsonPath('schema', VoxSchema::INTENT_RESPONSE)
            ->assertJsonPath('intent_packet.schema', VoxSchema::INTENT_PACKET)
            ->assertJsonPath('intent_packet.mode', 'dictation')
            ->assertJsonPath('intent_packet.risk_class', 'R0')
            ->assertJsonPath('intent_packet.compiled_prompt', null)
            ->assertJsonPath('intent_packet.compiled_prompt_template', null)
            ->assertJsonPath('intent_packet.goal', '')
            ->assertJsonPath('intent_packet.provider_hint', 'local')
            ->assertJsonPath('intent_packet.executor_hint', 'none')
            ->assertJsonPath('intent_packet.output_format', 'text')
            ->assertJsonPath('intent_packet.session_id', $sessionId)
            ->assertJsonPath('intent_packet.transcript_ref', $transcriptId)
            ->assertJsonPath('intent_packet.human_input_text', 'manda o Codex olhar esse módulo do voice sem mexer')
            ->assertJsonPath('intent_packet.constraints', [])
            ->assertJsonPath('intent_packet.context_refs.0.kind', 'none')
            ->assertJsonPath('intent_packet.context_refs.0.resolved', true)
            ->assertJsonPath('intent_packet.compiler_version', VoxSchema::COMPILER_VERSION)
            ->assertJsonPath('receipt.schema', VoxSchema::RECEIPT_R0)
            ->assertJsonPath('receipt.receipt_type', 'vox_r0_no_op_pass_through')
            ->assertJsonPath('receipt.risk_class', 'R0')
            ->assertJsonPath('receipt.provider_call_allowed', false)
            ->assertJsonPath('receipt.tool_call_allowed', false)
            ->assertJsonPath('receipt.raw_audio_allowed', false)
            ->assertJsonPath('receipt.external_side_effect_allowed', false)
            ->assertJsonPath('receipt.action_authorized', 'return_text_to_desktop')
            ->assertJsonPath('confirmation_required', false)
            ->assertJsonPath('actions_available.0', 'copy_to_clipboard')
            ->assertJsonPath('actions_available.1', 'insert_text')
            ->assertJsonPath('actions_available.2', 'cancel');

        $events = $response->json('events');
        $this->assertIsArray($events);
        $kinds = array_column($events, 'event_kind');
        $this->assertContains('VOX_TRANSCRIPT_READY', $kinds);
        $this->assertContains('VOX_INTENT_COMPILED', $kinds);
        $this->assertContains('VOX_POLICY_EVALUATED', $kinds);
    }

    public function test_intent_rejects_audio_bytes_field(): void
    {
        $body = $this->validTranscript();
        $body['audio_bytes'] = base64_encode('not allowed');
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio_bytes']);
    }

    public function test_intent_rejects_raw_audio_field(): void
    {
        $body = $this->validTranscript();
        $body['raw_audio'] = 'AAAA';
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['raw_audio']);
    }

    public function test_intent_rejects_pcm_field(): void
    {
        $body = $this->validTranscript();
        $body['pcm'] = [0, 0, 0];
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pcm']);
    }

    public function test_intent_rejects_raw_pcm_persisted_true(): void
    {
        $body = $this->validTranscript(['raw_pcm_persisted' => true]);
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['raw_pcm_persisted']);
    }

    public function test_intent_rejects_non_pt_br_language(): void
    {
        $body = $this->validTranscript(['language' => 'en-US']);
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['language']);
    }

    public function test_intent_rejects_non_dictation_mode(): void
    {
        $body = $this->validTranscript(['mode_requested' => 'intent_compile']);
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode_requested']);
    }

    public function test_intent_rejects_eclipse_aborted_mid_capture(): void
    {
        $body = $this->validTranscript(['eclipse_check' => 'aborted_mid_capture']);
        $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['eclipse_check']);
    }

    public function test_execute_copy_to_clipboard_returns_action_outcome_and_desktop_action(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'text' => 'fala que vai pro clipboard',
        ]), $this->headers)->assertOk()->json();

        $intentId = $intent['intent_packet']['intent_id'];
        $receiptId = $intent['receipt']['receipt_id'];

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intentId,
            'receipt_id' => $receiptId,
            'decision' => 'copy_to_clipboard',
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('schema', VoxSchema::EXECUTE_RESPONSE)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('action_outcome.schema', VoxSchema::ACTION_OUTCOME)
            ->assertJsonPath('action_outcome.executor', 'clipboard_write')
            ->assertJsonPath('action_outcome.status', 'completed')
            ->assertJsonPath('action_outcome.error', null)
            ->assertJsonPath('action_outcome.executor_violations', [])
            ->assertJsonPath('action_outcome.regret_signals', [])
            ->assertJsonPath('desktop_action.kind', 'copy_to_clipboard')
            ->assertJsonPath('desktop_action.text', 'fala que vai pro clipboard');

        // Receipt + intent become single-use: replaying execute is rejected.
        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intentId,
            'receipt_id' => $receiptId,
            'decision' => 'copy_to_clipboard',
        ], $this->headers)->assertStatus(422)->assertJsonValidationErrors(['intent_id']);
    }

    public function test_execute_cancel_returns_aborted_outcome_and_no_desktop_action(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'text' => 'cancela isso',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'cancel',
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('action_outcome.status', 'aborted')
            ->assertJsonPath('action_outcome.executor', 'no_op_dictation')
            ->assertJsonPath('action_outcome.error.kind', 'operator_cancelled')
            ->assertJsonPath('desktop_action', null);
    }

    public function test_execute_rejects_unknown_intent_receipt_pair(): void
    {
        $this->postJson('/ai/vox/execute', [
            'intent_id' => (string) Str::uuid(),
            'receipt_id' => 'rcpt_vox_'.Str::uuid(),
            'decision' => 'copy_to_clipboard',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intent_id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validTranscript(array $overrides = []): array
    {
        return array_replace([
            'schema' => VoxSchema::TRANSCRIPT,
            'session_id' => (string) Str::uuid(),
            'transcript_id' => (string) Str::uuid(),
            'audio_handle' => (string) Str::uuid(),
            'language' => 'pt-BR',
            'engine' => 'whisper.cpp@large-v3',
            'engine_invocation_id' => (string) Str::uuid(),
            'text' => 'isso aqui é um ditado de teste',
            'text_raw' => 'isso aqui é um ditado de teste',
            'confidence' => 0.91,
            'words' => [],
            'personal_dictionary_applied' => ['Codex', 'LiveKit'],
            'post_corrections' => [],
            'raw_pcm_persisted' => false,
            'eclipse_check' => 'passed',
            'mode_requested' => 'dictation',
        ], $overrides);
    }
}
