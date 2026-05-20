<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Atlas Vox V6.5 · Contract Compatibility Guard — backend side.
 *
 * Pergunta única: "o `/ai/vox/intent` continua emitindo o response shape
 * V6 (intent_packet, receipt, preview, confirmation_required) E pode
 * adicionar os campos additive V6.5/V6.8 (flow_decision, prompt_quality,
 * interlocutor, auto_mode_decision, cognitive_flow_governor) sem quebrar
 * clientes antigos?"
 *
 * Cada teste cobre uma regressão concreta:
 *   1. Schema canônico do response permanece `atlas.vox.intent_response.v1`.
 *   2. Campos legados obrigatórios presentes (intent_packet, receipt,
 *      preview, confirmation_required, actions_available).
 *   3. Campos additive V6.5 quando presentes carregam schema canônico.
 *   4. Response NUNCA aninha `session_id` dentro de `transcript` (legacy).
 *   5. Response NUNCA expõe `confirmation_token` cru em modos sem confirmação.
 *
 * Read-only: usa Artisan + endpoint HTTP via `postJson()`. Nada de microfone.
 */
final class AtlasAiVoxResponseCompatibilityTest extends TestCase
{
    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        // O controller registra evidence no ledger, então precisamos da tabela.
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function transcriptPayload(string $text, string $mode): array
    {
        return [
            'schema' => VoxSchema::TRANSCRIPT,
            'session_id' => 'sess_compat_'.bin2hex(random_bytes(4)),
            'transcript_id' => 'tr_compat_'.bin2hex(random_bytes(4)),
            'audio_handle' => 'aud_compat_'.bin2hex(random_bytes(4)),
            'language' => 'pt-BR',
            'engine' => 'whisper.cpp@large-v3',
            'engine_invocation_id' => 'inv_'.bin2hex(random_bytes(4)),
            'text' => $text,
            'text_raw' => $text,
            'confidence' => 0.91,
            'words' => [],
            'personal_dictionary_applied' => [],
            'post_corrections' => [],
            'latency_ms' => [
                'capture_to_stt_start' => 8,
                'stt_processing' => 480,
                'correction_pass' => 2,
                'total' => 490,
            ],
            'raw_pcm_persisted' => false,
            'eclipse_check' => 'passed',
            'created_at' => '2026-05-19T18:00:00.000Z',
            'source' => 'desktop_overlay',
            'mode_requested' => $mode,
        ];
    }

    public function test_response_carries_canonical_schema_and_legacy_v6_fields(): void
    {
        $payload = $this->transcriptPayload(
            'investiga o overlay do Atlas Vox sem mexer no kernel',
            VoxSchema::MODE_INTENT_COMPILE,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $body = $response->json();
        // Schema canônico permanece.
        $this->assertSame(VoxSchema::INTENT_RESPONSE, $body['schema']);
        // V6 legados — UI antiga continua funcionando.
        foreach (['intent_packet', 'receipt', 'preview', 'confirmation_required', 'actions_available'] as $required) {
            $this->assertArrayHasKey($required, $body, "campo legado '$required' sumiu do response");
        }
        // Tipo correto dos campos legados.
        $this->assertIsArray($body['intent_packet']);
        $this->assertIsArray($body['receipt']);
        $this->assertIsArray($body['actions_available']);
        $this->assertIsBool($body['confirmation_required']);
    }

    public function test_response_carries_v65_additive_fields_when_emitted(): void
    {
        $payload = $this->transcriptPayload(
            'Codex investiga por que o overlay quebra sem mexer no VoxEvidenceService',
            VoxSchema::MODE_INTENT_COMPILE,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $body = $response->json();
        // V6.5 additive · todos opcionais no contrato; cada um carrega
        // schema canônico próprio quando presente.
        if (array_key_exists('flow_decision', $body) && $body['flow_decision'] !== null) {
            $this->assertSame('atlas.vox.flow_decision.v1', $body['flow_decision']['schema']);
        }
        // prompt_quality vive dentro de intent_packet (canon backend).
        $promptQuality = $body['intent_packet']['prompt_quality'] ?? null;
        if ($promptQuality !== null) {
            $this->assertSame('atlas.vox.prompt_quality.v1', $promptQuality['schema']);
            $this->assertContains($promptQuality['status'], ['pass', 'warn', 'fail']);
            $this->assertGreaterThanOrEqual(0, (float) $promptQuality['score']);
            $this->assertLessThanOrEqual(1, (float) $promptQuality['score']);
            $this->assertIsArray($promptQuality['issues']);
            $this->assertIsBool($promptQuality['needs_review']);
            $this->assertIsBool($promptQuality['repaired']);
        }
        if (isset($body['interlocutor']) && $body['interlocutor'] !== null) {
            $this->assertSame('atlas.vox.interlocutor_decision.v1', $body['interlocutor']['schema']);
        }
        if (isset($body['auto_mode_decision']) && $body['auto_mode_decision'] !== null) {
            $this->assertSame('atlas.vox.auto_mode_decision.v1', $body['auto_mode_decision']['schema']);
        }
        if (isset($body['cognitive_flow_governor']) && $body['cognitive_flow_governor'] !== null) {
            $this->assertSame(VoxSchema::COGNITIVE_FLOW_GOVERNOR, $body['cognitive_flow_governor']['schema']);
            $this->assertTrue($body['cognitive_flow_governor']['local_only']);
            $this->assertFalse($body['cognitive_flow_governor']['v7_unlock_allowed']);
            $this->assertFalse($body['cognitive_flow_governor']['guards']['terminal_execute']);
            $this->assertFalse($body['cognitive_flow_governor']['guards']['paid_api_required']);
            $this->assertFalse($body['cognitive_flow_governor']['guards']['mobile_touched']);
            $this->assertFalse($body['cognitive_flow_governor']['guards']['voice_realtime_touched']);
        }
    }

    public function test_response_never_nests_session_id_inside_transcript_legacy_wrapper(): void
    {
        $payload = $this->transcriptPayload(
            'analisa rapidinho o estado do canon V6',
            VoxSchema::MODE_INTENT_COMPILE,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $body = $response->json();
        // Response NUNCA pode reintroduzir o wrapper legado V0.
        $this->assertArrayNotHasKey(
            'transcript',
            $body,
            'response voltou a embrulhar transcript — quebra Kernel V3+',
        );
        // session_id continua na raiz (via intent_packet).
        $this->assertArrayHasKey('session_id', $body['intent_packet']);
        $this->assertNotEmpty($body['intent_packet']['session_id']);
    }

    public function test_response_does_not_expose_confirmation_token_in_non_confirming_mode(): void
    {
        $payload = $this->transcriptPayload(
            'investiga o overlay sem mexer em nada',
            VoxSchema::MODE_INTENT_COMPILE,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $body = $response->json();
        // Em intent_compile R1 (leitura) não há confirmation_request,
        // então confirmation_token jamais pode vazar serializado.
        $this->assertFalse((bool) $body['confirmation_required']);
        $this->assertNull($body['confirmation_request']);
        $serialized = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            'confirmation_token',
            $serialized,
            'response vazou confirmation_token em modo sem confirmação',
        );
    }

    public function test_response_handles_dictation_mode_without_v65_additive_blowing_up(): void
    {
        // Dictation é V0 — não tem compiled_prompt e portanto não emite
        // prompt_quality. Garantimos que o response continua válido e
        // os additive ficam null/ausentes sem quebrar.
        $payload = $this->transcriptPayload(
            'lembrete: comprar café amanhã',
            VoxSchema::MODE_DICTATION,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(VoxSchema::INTENT_RESPONSE, $body['schema']);
        $this->assertArrayHasKey('intent_packet', $body);
        $this->assertNull($body['intent_packet']['compiled_prompt']);
        // prompt_quality NÃO deve existir em dictation (não há prompt para criticar).
        $this->assertArrayNotHasKey('prompt_quality', $body['intent_packet']);
    }

    public function test_response_serialises_to_clean_json_under_500_kb(): void
    {
        // Sanity check operacional — response não pode ter inflado por bug.
        $payload = $this->transcriptPayload(
            'Codex investiga por que o build do desktop está quebrando sem mexer no kernel',
            VoxSchema::MODE_INTENT_COMPILE,
        );
        $response = $this->postJson('/ai/vox/intent', $payload, $this->headers);
        $response->assertStatus(200);

        $raw = $response->getContent();
        $this->assertIsString($raw);
        $this->assertLessThan(500_000, strlen($raw), 'response inflou acima de 500 KB');
        // Nenhum token nem campo proibido leakado.
        $this->assertStringNotContainsString('ATLAS_TOKEN', $raw);
        $this->assertStringNotContainsString('Bearer ', $raw);
    }
}
