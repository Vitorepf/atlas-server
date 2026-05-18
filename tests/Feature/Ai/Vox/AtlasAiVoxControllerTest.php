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

    public function test_health_advertises_dictation_polish_intent_and_governed_execute(): void
    {
        $this->getJson('/ai/vox/health', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxSchema::HEALTH)
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('mode', 'dictation_polish_intent_and_governed_execute')
            ->assertJsonPath('voice_realtime_status', 'paused_until_v6')
            ->assertJsonPath('supports.dictation', true)
            ->assertJsonPath('supports.prompt_polish', true)
            ->assertJsonPath('supports.intent_compile', true)
            ->assertJsonPath('supports.governed_execute', true)
            ->assertJsonPath('prompt_polish.engine', 'deterministic_rules')
            ->assertJsonPath('prompt_polish.language', 'pt-BR')
            ->assertJsonPath('prompt_polish.provider_call', false)
            ->assertJsonPath('prompt_polish.llm_call', false)
            ->assertJsonPath('intent_compile.engine', 'deterministic_rules')
            ->assertJsonPath('intent_compile.language', 'pt-BR')
            ->assertJsonPath('intent_compile.provider_call', false)
            ->assertJsonPath('intent_compile.llm_call', false)
            ->assertJsonPath('intent_compile.executes', false)
            ->assertJsonPath('governed_execute.terminal_execute', false)
            ->assertJsonPath('governed_execute.destructive_auto_execute', false)
            ->assertJsonPath('governed_execute.confirmation_required_for.0', 'R2')
            ->assertJsonPath('governed_execute.literal_confirmation_required_for.0', 'R4')
            ->assertJsonPath('executors.terminal_propose.available', true)
            ->assertJsonPath('executors.terminal_propose.never_executes', true)
            ->assertJsonPath('executors.codex_cli.available', false)
            ->assertJsonPath('executors.claude_cli.available', false)
            ->assertJsonPath('executors.filesystem_edit.available', false)
            ->assertJsonPath('kernel_guarantees.tool_call', false)
            ->assertJsonPath('kernel_guarantees.raw_audio_accepted', false)
            ->assertJsonPath('kernel_guarantees.terminal_execute', false)
            ->assertJsonPath('kernel_guarantees.destructive_auto_execute', false)
            ->assertJsonPath('kernel_guarantees.confirmation_token_in_ledger', false)
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

    public function test_intent_now_accepts_governed_execute_mode_and_returns_confirmation_request(): void
    {
        $body = $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'manda o codex olhar esse modulo do voice sem mexer',
        ]);
        $response = $this->postJson('/ai/vox/intent', $body, $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxSchema::INTENT_RESPONSE)
            ->assertJsonPath('intent_packet.mode', 'governed_execute')
            ->assertJsonPath('confirmation_required', true);

        $request = $response->json('confirmation_request');
        $this->assertIsArray($request);
        $this->assertSame(VoxSchema::CONFIRMATION_REQUEST, $request['schema']);
        $this->assertNotEmpty($request['confirmation_token']);
        $this->assertNotEmpty($request['request_id']);
        $this->assertContains('execute', $request['actions_available']);
        $this->assertContains('cancel', $request['actions_available']);
        $this->assertContains('edit_intent', $request['actions_available']);

        $events = $response->json('events');
        $kinds = array_column($events, 'event_kind');
        $this->assertContains('VOX_CONFIRMATION_REQUESTED', $kinds);

        // Token must NEVER appear in the ledger event payload.
        foreach ($events as $event) {
            if ($event['event_kind'] === 'VOX_CONFIRMATION_REQUESTED') {
                $this->assertArrayNotHasKey('confirmation_token', $event['payload']);
                $this->assertSame(false, $event['payload']['confirmation_token_in_ledger']);
            }
        }
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

    public function test_intent_prompt_polish_returns_compiled_prompt_with_r0_receipt_and_audit_events(): void
    {
        $sessionId = (string) Str::uuid();
        $transcriptId = (string) Str::uuid();

        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'session_id' => $sessionId,
            'transcript_id' => $transcriptId,
            'mode_requested' => 'prompt_polish',
            'text' => 'atlas manda o codex olhar esse trem do live kit aí mas não mexer não.',
        ]), $this->headers);

        $response->assertOk()
            ->assertJsonPath('schema', VoxSchema::INTENT_RESPONSE)
            ->assertJsonPath('intent_packet.schema', VoxSchema::INTENT_PACKET)
            ->assertJsonPath('intent_packet.mode', 'prompt_polish')
            ->assertJsonPath('intent_packet.risk_class', 'R0')
            ->assertJsonPath('intent_packet.compiled_prompt_template', 'builtin.prompt_polish.pt-br@0.1.0')
            ->assertJsonPath('intent_packet.provider_hint', 'codex_cli')
            ->assertJsonPath('intent_packet.executor_hint', 'none')
            ->assertJsonPath('intent_packet.output_format', 'text')
            ->assertJsonPath('intent_packet.human_input_text', 'atlas manda o codex olhar esse trem do live kit aí mas não mexer não.')
            ->assertJsonPath('intent_packet.context_refs.0.kind', 'none')
            ->assertJsonPath('receipt.schema', VoxSchema::RECEIPT_R0)
            ->assertJsonPath('receipt.provider_call_allowed', false)
            ->assertJsonPath('receipt.tool_call_allowed', false)
            ->assertJsonPath('receipt.raw_audio_allowed', false)
            ->assertJsonPath('receipt.external_side_effect_allowed', false)
            ->assertJsonPath('confirmation_required', false)
            ->assertJsonPath('actions_available.0', 'copy_compiled_prompt')
            ->assertJsonPath('actions_available.1', 'insert_compiled_prompt')
            ->assertJsonPath('actions_available.2', 'copy_original')
            ->assertJsonPath('actions_available.3', 'cancel');

        $compiled = $response->json('intent_packet.compiled_prompt');
        $this->assertIsString($compiled);
        $this->assertNotSame('', $compiled);
        // Polisher must preserve the negation verbatim.
        $this->assertStringContainsString('não mexer', $compiled);
        // And it must normalise Atlas-domain term casing.
        $this->assertStringContainsString('Codex', $compiled);
        $this->assertStringContainsString('LiveKit', $compiled);
        // Filler "aí" must be stripped when not at start.
        $this->assertStringNotContainsString(' aí ', $compiled);

        $events = $response->json('events');
        $this->assertIsArray($events);
        $kinds = array_column($events, 'event_kind');
        $this->assertContains('VOX_TRANSCRIPT_READY', $kinds);
        $this->assertContains('VOX_INTENT_COMPILED', $kinds);
        $this->assertContains('VOX_PROMPT_COMPILED', $kinds);
        $this->assertContains('VOX_POLICY_EVALUATED', $kinds);

        $preview = $response->json('preview');
        $this->assertSame($compiled, $preview['compiled_prompt']);
        $this->assertSame('builtin.prompt_polish.pt-br@0.1.0', $preview['compiled_prompt_template']);
        $this->assertContains('não mexer', $preview['constraints']);
    }

    public function test_execute_copy_compiled_prompt_returns_compiled_text_and_records_evidence(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'prompt_polish',
            'text' => 'manda o codex investigar o módulo voice mas não editar arquivos',
        ]), $this->headers)->assertOk()->json();

        $compiled = $intent['intent_packet']['compiled_prompt'];
        $this->assertIsString($compiled);
        $this->assertNotSame('', $compiled);

        $execute = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_compiled_prompt',
        ], $this->headers);

        $execute->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('action_outcome.executor', 'clipboard_write')
            ->assertJsonPath('action_outcome.metadata.source_text', 'compiled_prompt')
            ->assertJsonPath('action_outcome.metadata.intent_mode', 'prompt_polish')
            ->assertJsonPath('desktop_action.kind', 'copy_compiled_prompt')
            ->assertJsonPath('desktop_action.source_text', 'compiled_prompt')
            ->assertJsonPath('desktop_action.text', $compiled);

        $events = $execute->json('events');
        $this->assertIsArray($events);
        $kinds = array_column($events, 'event_kind');
        $this->assertContains('VOX_EVIDENCE_RECORDED', $kinds);
    }

    public function test_execute_copy_original_in_prompt_polish_returns_human_input_text(): void
    {
        $original = 'manda o claude pensar nisso aqui';
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'prompt_polish',
            'text' => $original,
        ]), $this->headers)->assertOk()->json();

        $execute = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_original',
        ], $this->headers);

        $execute->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('desktop_action.kind', 'copy_original')
            ->assertJsonPath('desktop_action.source_text', 'original')
            ->assertJsonPath('desktop_action.text', $original)
            ->assertJsonPath('action_outcome.metadata.source_text', 'original');
    }

    public function test_execute_rejects_compiled_prompt_decision_for_dictation_mode(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'dictation',
            'text' => 'só ditado puro',
        ]), $this->headers)->assertOk()->json();

        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_compiled_prompt',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    public function test_execute_rejects_original_decision_for_dictation_mode(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'dictation',
            'text' => 'só ditado puro',
        ]), $this->headers)->assertOk()->json();

        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_original',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    // ───────────────────────────────────────────────────────────────
    // V2 intent_compile (Onda 5 / Claude G)
    // ───────────────────────────────────────────────────────────────

    public function test_intent_compile_returns_full_packet_with_compiled_prompt_template_and_evidence(): void
    {
        $sessionId = (string) Str::uuid();

        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'session_id' => $sessionId,
            'mode_requested' => 'intent_compile',
            'text' => 'manda o codex investigar o módulo Vox sem editar arquivos, só me devolve o diagnóstico',
        ]), $this->headers);

        $response->assertOk()
            ->assertJsonPath('schema', VoxSchema::INTENT_RESPONSE)
            ->assertJsonPath('intent_packet.schema', VoxSchema::INTENT_PACKET)
            ->assertJsonPath('intent_packet.mode', 'intent_compile')
            ->assertJsonPath('intent_packet.risk_class', 'R1')
            ->assertJsonPath('intent_packet.provider_hint', 'codex_cli')
            ->assertJsonPath('intent_packet.output_format', 'diagnostic')
            ->assertJsonPath('intent_packet.executor_hint', 'none')
            ->assertJsonPath('intent_packet.compiled_prompt_template', 'builtin.intent_compile.codex_cli.diagnostic.pt-br@0.1.0')
            ->assertJsonPath('intent_packet.human_input_text', 'manda o codex investigar o módulo Vox sem editar arquivos, só me devolve o diagnóstico')
            ->assertJsonPath('confirmation_required', false)
            ->assertJsonPath('actions_available.0', 'copy_compiled_prompt')
            ->assertJsonPath('actions_available.1', 'insert_compiled_prompt')
            ->assertJsonPath('actions_available.2', 'copy_original')
            ->assertJsonPath('actions_available.3', 'cancel');

        $packet = $response->json('intent_packet');
        $this->assertIsString($packet['compiled_prompt']);
        $this->assertNotSame('', $packet['compiled_prompt']);
        $this->assertStringContainsString('## Contexto', $packet['compiled_prompt']);
        $this->assertStringContainsString('## Objetivo', $packet['compiled_prompt']);
        $this->assertStringContainsString('## Modo de trabalho', $packet['compiled_prompt']);
        $this->assertStringContainsString('## Restrições', $packet['compiled_prompt']);
        $this->assertStringContainsString('sem editar arquivos', $packet['compiled_prompt']);
        $this->assertContains('sem editar arquivos', $packet['constraints']);

        $kinds = array_column($response->json('events'), 'event_kind');
        $this->assertContains('VOX_TRANSCRIPT_READY', $kinds);
        $this->assertContains('VOX_INTENT_COMPILED', $kinds);
        $this->assertContains('VOX_PROMPT_COMPILED', $kinds);
        $this->assertContains('VOX_POLICY_EVALUATED', $kinds);
    }

    public function test_intent_compile_r0_path_issues_r0_receipt(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'limpa e formata esse texto pra eu colar',
        ]), $this->headers)->assertOk();

        $response->assertJsonPath('intent_packet.risk_class', 'R0')
            ->assertJsonPath('receipt.schema', VoxSchema::RECEIPT_R0)
            ->assertJsonPath('receipt.executable_now', true)
            ->assertJsonPath('receipt.confirmation_required', false)
            ->assertJsonPath('receipt.provider_call_allowed', false)
            ->assertJsonPath('receipt.tool_call_allowed', false)
            ->assertJsonPath('receipt.raw_audio_allowed', false)
            ->assertJsonPath('receipt.external_side_effect_allowed', false);
    }

    public function test_intent_compile_r4_destructive_command_emits_advisory_receipt_with_double_confirmation_flag(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'manda um rm -rf no diretório do Vox pra limpar',
        ]), $this->headers)->assertOk();

        $response->assertJsonPath('intent_packet.risk_class', 'R4')
            ->assertJsonPath('receipt.schema', 'atlas.vox.receipt.advisory.v1')
            ->assertJsonPath('receipt.executable_now', false)
            ->assertJsonPath('receipt.confirmation_required', true)
            ->assertJsonPath('receipt.future_governance.will_require_double_confirmation', true)
            ->assertJsonPath('receipt.provider_call_allowed', false)
            ->assertJsonPath('receipt.tool_call_allowed', false)
            ->assertJsonPath('receipt.external_side_effect_allowed', false);

        $compiled = $response->json('intent_packet.compiled_prompt');
        $this->assertIsString($compiled);
        $this->assertStringContainsString('## Segurança', $compiled);
        $this->assertStringContainsString('destrutiva', $compiled);

        $markers = $response->json('intent_packet.compiler_telemetry.risk_markers');
        $this->assertIsArray($markers);
        $this->assertContains('rm_rf', $markers);
    }

    public function test_intent_compile_r3_run_tests_proposes_terminal_with_safety_block(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'roda os testes do Vox e me diz o que quebrou',
        ]), $this->headers)->assertOk();

        $response->assertJsonPath('intent_packet.risk_class', 'R3')
            ->assertJsonPath('intent_packet.executor_hint', 'terminal_propose')
            ->assertJsonPath('receipt.schema', 'atlas.vox.receipt.advisory.v1')
            ->assertJsonPath('receipt.executable_now', false)
            ->assertJsonPath('receipt.confirmation_required', true);

        $this->assertStringContainsString('## Segurança', $response->json('intent_packet.compiled_prompt'));
    }

    public function test_intent_compile_respects_explicit_request_provider_when_voice_is_silent(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'provider_hint' => 'claude_cli',
            'output_format' => 'plan',
            'text' => 'quero um plano com riscos pra essa migração',
        ]), $this->headers)->assertOk();

        $response->assertJsonPath('intent_packet.provider_hint', 'claude_cli')
            ->assertJsonPath('intent_packet.output_format', 'plan')
            ->assertJsonPath('intent_packet.compiler_telemetry.provider_hint_source', 'request_explicit')
            ->assertJsonPath('intent_packet.compiler_telemetry.output_format_source', 'request_explicit');
    }

    public function test_intent_compile_does_not_invent_provider_when_request_contradicts_voice(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'provider_hint' => 'claude_cli',
            'text' => 'manda o codex investigar isso',
        ]), $this->headers)->assertOk();

        $response->assertJsonPath('intent_packet.provider_hint', 'auto')
            ->assertJsonPath('intent_packet.compiler_telemetry.provider_hint_source', 'request_overruled_by_voice');
    }

    public function test_intent_compile_propagates_valid_context_refs_to_packet(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'investiga esse fluxo',
            'context_refs' => [
                ['kind' => 'file', 'ref' => '/Users/vitor/dev/atlas/foo.php', 'resolved' => true],
                ['kind' => 'selection', 'ref' => 'sel-42', 'resolved' => false],
            ],
        ]), $this->headers)->assertOk();

        $refs = $response->json('intent_packet.context_refs');
        $this->assertIsArray($refs);
        $this->assertCount(2, $refs);
        $this->assertSame('file', $refs[0]['kind']);
        $this->assertSame('selection', $refs[1]['kind']);
    }

    public function test_intent_compile_rejects_invalid_context_ref_kind_at_validation_boundary(): void
    {
        $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'investiga esse fluxo',
            'context_refs' => [
                ['kind' => 'totally_invalid', 'ref' => 'x'],
            ],
        ]), $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['context_refs.0.kind']);
    }

    public function test_intent_compile_execute_copy_compiled_prompt_returns_compiled_text(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'manda o codex investigar o módulo Vox sem editar arquivos',
        ]), $this->headers)->assertOk()->json();

        $compiled = $intent['intent_packet']['compiled_prompt'];

        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_compiled_prompt',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('action_outcome.metadata.source_text', 'compiled_prompt')
            ->assertJsonPath('action_outcome.metadata.intent_mode', 'intent_compile')
            ->assertJsonPath('desktop_action.kind', 'copy_compiled_prompt')
            ->assertJsonPath('desktop_action.source_text', 'compiled_prompt')
            ->assertJsonPath('desktop_action.text', $compiled);
    }

    public function test_intent_compile_execute_copy_original_returns_human_input_text(): void
    {
        $original = 'manda o codex investigar o módulo Vox sem editar arquivos';
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => $original,
        ]), $this->headers)->assertOk()->json();

        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_original',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('desktop_action.source_text', 'original')
            ->assertJsonPath('desktop_action.text', $original);
    }

    public function test_intent_compile_rejects_legacy_dictation_decisions(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'investiga esse fluxo',
        ]), $this->headers)->assertOk()->json();

        $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'copy_to_clipboard',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    public function test_intent_compile_preserves_constraints_in_compiled_prompt_and_packet(): void
    {
        $response = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'text' => 'analisa esse fluxo, sem editar arquivos e não toca no banco',
        ]), $this->headers)->assertOk();

        $constraints = $response->json('intent_packet.constraints');
        $this->assertIsArray($constraints);
        $this->assertContains('sem editar arquivos', $constraints);
        $this->assertContains('não toca no banco', $constraints);

        $compiled = $response->json('intent_packet.compiled_prompt');
        $this->assertStringContainsString('sem editar arquivos', $compiled);
        $this->assertStringContainsString('não toca no banco', $compiled);
    }

    public function test_intent_compile_rejects_invalid_provider_hint(): void
    {
        $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'provider_hint' => 'openai_gpt5',
            'text' => 'qualquer coisa',
        ]), $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider_hint']);
    }

    public function test_intent_compile_rejects_invalid_output_format(): void
    {
        $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'intent_compile',
            'output_format' => 'video',
            'text' => 'qualquer coisa',
        ]), $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['output_format']);
    }

    // ──────────────────────────────────────────────────────────────────
    // V3 governed_execute · Wave 6 · Claude I
    // ──────────────────────────────────────────────────────────────────

    public function test_governed_execute_without_confirmation_token_blocks_with_422(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'salva isso em uma nota Atlas',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'save_as_note',
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation_token']);
    }

    public function test_governed_execute_with_invalid_token_is_blocked_by_gate_no_executor_runs(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'salva isso como nota',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'save_as_note',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => 'hmac_sha256:deadbeef',
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason_code', 'confirmation_token_invalid');
    }

    public function test_governed_execute_terminal_propose_never_runs_a_process(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'roda os testes da suíte Vox no terminal',
        ]), $this->headers)->assertOk()->json();

        $token = $intent['confirmation_request']['confirmation_token'];
        $requestId = $intent['confirmation_request']['request_id'];

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $requestId,
            'confirmation_token' => $token,
            'literal_confirmation_input' => $intent['confirmation_request']['literal_confirmation_text'] ?? null,
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('action_outcome.executor', 'terminal_propose')
            ->assertJsonPath('action_outcome.status', 'completed')
            ->assertJsonPath('action_outcome.metadata.command_executed', false)
            ->assertJsonPath('desktop_action.kind', 'terminal_proposal')
            ->assertJsonPath('desktop_action.command_executed', false);

        $events = $response->json('events');
        $this->assertContains('VOX_ACTION_DISPATCHED', array_column($events, 'event_kind'));
    }

    public function test_governed_execute_hard_veto_blocks_rm_rf_even_with_valid_token(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'roda rm -rf no servidor agora',
        ]), $this->headers)->assertOk()->json();

        // R4 path requires literal confirmation; the gate's hard veto should
        // fire BEFORE the literal check (defense in depth).
        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
            'literal_confirmation_input' => $intent['confirmation_request']['literal_confirmation_text'],
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'blocked');
        $this->assertStringStartsWith('hard_veto_', (string) $response->json('reason_code'));
    }

    public function test_governed_execute_hard_veto_blocks_git_push_force(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'roda git push --force pro main',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
            'literal_confirmation_input' => $intent['confirmation_request']['literal_confirmation_text'],
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'blocked');
        $this->assertSame('hard_veto_git_push_force', $response->json('reason_code'));
    }

    public function test_governed_execute_hard_veto_blocks_drop_database(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'roda drop database atlas no banco',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
            'literal_confirmation_input' => $intent['confirmation_request']['literal_confirmation_text'],
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'blocked');
        $this->assertSame('hard_veto_drop_database', $response->json('reason_code'));
    }

    public function test_governed_execute_r4_without_literal_text_is_rejected_by_gate(): void
    {
        // Force R4 via a destructive phrase (deploy automático) that the
        // extractor classifies as R4 but is NOT in the hard-veto list.
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'faz deploy agora pra produção',
        ]), $this->headers)->assertOk()->json();

        $this->assertSame('R4', $intent['intent_packet']['risk_class']);
        $this->assertTrue($intent['confirmation_request']['requires_literal_confirmation']);

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
            // literal_confirmation_input intentionally absent
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason_code', 'literal_confirmation_mismatch');
    }

    public function test_governed_execute_save_as_note_falls_back_to_desktop_action_when_inbox_absent(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'salva essa fala como nota Atlas para depois',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'save_as_note',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('action_outcome.executor', 'note_capture')
            ->assertJsonPath('action_outcome.status', 'escalated')
            ->assertJsonPath('action_outcome.metadata.inbox_available', false)
            ->assertJsonPath('desktop_action.kind', 'save_as_note');
    }

    public function test_governed_execute_codex_cli_blocks_honestly_when_binary_unavailable(): void
    {
        // No ATLAS_VOX_CODEX_CLI_BIN set in test env → executor reports
        // unavailable → outcome status=aborted with reason codex_cli_unavailable.
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'provider_hint' => 'codex_cli',
            'text' => 'manda o codex investigar o módulo de vox sem mexer em arquivos',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'execute',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonPath('action_outcome.executor', 'codex_cli')
            ->assertJsonPath('action_outcome.status', 'aborted')
            ->assertJsonPath('action_outcome.error.kind', 'codex_cli_unavailable');
    }

    public function test_governed_execute_token_is_single_use_replay_is_rejected(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'salva como nota agora',
        ]), $this->headers)->assertOk()->json();

        $payload = [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'save_as_note',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
        ];

        $this->postJson('/ai/vox/execute', $payload, $this->headers)->assertOk();

        // Same intent+receipt+token replay must fail (intent stash gone +
        // confirmation slot consumed).
        $this->postJson('/ai/vox/execute', $payload, $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intent_id']);
    }

    public function test_governed_execute_cancel_records_aborted_outcome_and_no_dispatch(): void
    {
        $intent = $this->postJson('/ai/vox/intent', $this->validTranscript([
            'mode_requested' => 'governed_execute',
            'text' => 'salva isso como nota mas depois cancelo',
        ]), $this->headers)->assertOk()->json();

        $response = $this->postJson('/ai/vox/execute', [
            'intent_id' => $intent['intent_packet']['intent_id'],
            'receipt_id' => $intent['receipt']['receipt_id'],
            'decision' => 'cancel',
            'request_id' => $intent['confirmation_request']['request_id'],
            'confirmation_token' => $intent['confirmation_request']['confirmation_token'],
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('action_outcome.status', 'aborted')
            ->assertJsonPath('action_outcome.executor', 'no_op_dictation');

        $events = $response->json('events');
        $this->assertNotContains('VOX_ACTION_DISPATCHED', array_column($events, 'event_kind'));
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
