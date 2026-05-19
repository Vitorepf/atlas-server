<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Models\AtlasVoxDogfoodSession;
use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Gate\VoxV7UnlockGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V6-K · invariantes duros do dogfood + trava V7.
 *
 * Esses testes existem para travar regressões que comprometeriam o uso real:
 *   - registro de eventos novos (re_recorded / edited_transcript).
 *   - rejeição silenciosa de qualquer campo bruto/sensível em `auto_event`.
 *   - banco vazio (sem migrations) NÃO derruba o relatório.
 *   - V7 unlock NUNCA pode chegar a `unlocked=true` por código, mesmo com
 *     todos os critérios cumpridos via dados sintéticos.
 */
final class VoxDogfoodHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_25_010000_create_atlas_vox_dogfood_sessions_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    private function dogfood(): VoxDogfoodService
    {
        return $this->app->make(VoxDogfoodService::class);
    }

    public function test_auto_event_persists_re_recorded_and_edited_transcript(): void
    {
        $result = $this->dogfood()->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'final_mode' => VoxSchema::MODE_INTENT_COMPILE,
                're_recorded' => true,
                'edited_transcript' => true,
                'launch_source' => VoxDogfoodService::SOURCE_HOTKEY,
                'clicked_action' => VoxDogfoodService::CLICKED_SEND,
            ],
        ]);

        /** @var AtlasVoxDogfoodSession $session */
        $session = $result['session'];
        $autoEvent = $session->metadata['auto_event'] ?? [];

        $this->assertTrue($autoEvent['re_recorded'] ?? null, 're_recorded precisa persistir');
        $this->assertTrue($autoEvent['edited_transcript'] ?? null, 'edited_transcript precisa persistir');
        $this->assertFalse($autoEvent['raw_audio_persisted'] ?? null, 'raw_audio_persisted invariante false');
        $this->assertSame(VoxDogfoodService::SCHEMA_AUTO_EVENT, $autoEvent['schema'] ?? null);
    }

    public function test_auto_event_silently_drops_prohibited_fields(): void
    {
        $result = $this->dogfood()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'final_mode' => VoxSchema::MODE_DICTATION,
                // Tudo aqui em baixo precisa sumir antes de chegar ao banco.
                'transcript' => 'manda o codex investigar isso aqui',
                'transcript_text' => 'literal',
                'transcript_raw' => 'raw',
                'compiled_prompt' => 'algum prompt',
                'audio' => 'data:audio/wav;base64,AAAA',
                'audio_bytes' => 'AAAA',
                'pcm' => 'pcm-bytes',
                'wav' => 'wav-bytes',
                'clipboard' => 'segredo do clipboard',
                'confirmation_token' => 'tok_NEVER',
                'literal_confirmation_text' => 'execute deploy',
                'authorization' => 'Bearer abc',
                'bearer' => 'sk-ant-real-token',
                'api_key' => 'sk-proj-abc',
                'apikey' => 'sk-proj-abc',
                'secret' => 'shh',
                'password' => 'p@ss',
                'cookie' => 'session=zzz',
                'access_token' => 'at',
                'refresh_token' => 'rt',
                'stack_trace' => "/Users/vitor/app/Foo.php:42\n#0 Bar->baz()",
                'stacktrace' => 'idem',
                'exception_trace' => 'idem',
                'exception_message' => 'fatal: missing /etc/passwd',
                'error_stack' => '...',
                'error_message_full' => '...',
                'trace' => '...',
                'email' => 'vitor@example.com',
                'phone' => '+5511999999999',
                'cpf' => '123.456.789-00',
                'rg' => '12.345.678-9',
                'address' => 'Rua...',
                'personal_info' => '...',
                'home_path' => '/Users/vitor',
                'absolute_path' => '/Users/vitor/.atlas/secrets.json',
                'file_content' => '...',
                'file_body' => '...',
            ],
        ]);

        /** @var AtlasVoxDogfoodSession $session */
        $session = $result['session'];
        $autoEvent = $session->metadata['auto_event'] ?? [];
        $persistedJson = json_encode($session->metadata, JSON_UNESCAPED_UNICODE) ?: '';

        $forbidden = [
            'manda o codex investigar', 'literal', 'compiled_prompt', 'AAAA', 'pcm-bytes', 'wav-bytes',
            'segredo do clipboard', 'tok_NEVER', 'execute deploy', 'Bearer abc',
            'sk-ant-real-token', 'sk-proj-abc', 'shh', 'p@ss', 'session=zzz',
            'Foo.php:42', 'fatal:', 'vitor@example.com', '+5511999999999',
            '123.456.789-00', '12.345.678-9', '/Users/vitor', '/.atlas/secrets.json',
            'transcript_text', 'transcript_raw', 'compiled_prompt', 'audio_bytes',
            'authorization', 'bearer', 'api_key', 'apikey', 'secret', 'password',
            'cookie', 'access_token', 'refresh_token', 'stack_trace', 'stacktrace',
            'exception_trace', 'exception_message', 'error_stack', 'error_message_full',
            'trace', 'email', 'phone', 'cpf', 'rg', 'address', 'personal_info',
            'home_path', 'absolute_path', 'file_content', 'file_body',
        ];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $persistedJson,
                "campo/valor proibido escapou para o banco: {$needle}",
            );
        }
        // Auto event SÓ pode conter chaves do whitelist.
        $allowed = array_merge(VoxDogfoodService::autoEventFields(), ['raw_audio_persisted', 'schema']);
        foreach (array_keys($autoEvent) as $key) {
            $this->assertContains($key, $allowed, "chave fora do whitelist persistiu: {$key}");
        }
    }

    public function test_report_does_not_break_when_table_is_empty(): void
    {
        // Tabela existe (criada no setUp) mas está vazia. Caso real de
        // Vitor que acabou de rodar `php artisan migrate`. O relatório
        // precisa devolver shape honesto, sem dividir por zero.
        $report = $this->dogfood()->report();

        $this->assertSame(VoxDogfoodService::SCHEMA_REPORT, $report['schema']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['sessions_total']);
        $this->assertSame(0.0, $report['success_rate']);
        $this->assertSame(0.0, $report['regret_rate']);
        $this->assertSame(0.0, $report['re_recorded_rate'] ?? 0.0);
        $this->assertSame(0.0, $report['edited_transcript_rate'] ?? 0.0);
        $this->assertSame('no_dogfood_sessions_yet', $report['recommendation']);
    }

    public function test_report_does_not_break_when_table_is_missing(): void
    {
        // Cenário ainda mais hostil: tabela inexistente (operador esqueceu
        // de rodar migrate). O relatório precisa devolver shape honesto e
        // recommendation `no_dogfood_sessions_yet` — nunca explodir.
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        $report = $this->dogfood()->report();

        $this->assertSame(VoxDogfoodService::SCHEMA_REPORT, $report['schema']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['sessions_total']);
        $this->assertSame('no_dogfood_sessions_yet', $report['recommendation']);
    }

    public function test_v7_unlock_gate_stays_false_even_with_synthetic_perfect_data(): void
    {
        // Forja uma snapshot perfeita: o gate ainda assim deve responder
        // `unlocked=false`. Quem destrava V7 é doutrina + ADR, NÃO código.
        $metricsStub = new class extends VoxMetricsService {
            public function snapshot(): array
            {
                return [
                    'schema' => VoxMetricsService::SCHEMA,
                    'status' => 'ok',
                    'summary' => [
                        'total_sessions' => 9999,
                        'real_usage_days' => 9999,
                        'average_sessions_per_day' => 9.9,
                        'first_session_at' => '2026-01-01T00:00:00Z',
                        'last_session_at' => '2026-12-31T23:59:59Z',
                        'dictionary_correction_count' => 9999,
                        'stt_wer_estimate' => null,
                    ],
                    'modes' => [],
                    'safety' => [],
                    'rivals' => [],
                    'hard_gates' => [
                        'raw_audio_persisted_count' => 0,
                        'confirmation_bypass_count' => 0,
                        'destructive_action_without_receipt' => 0,
                        'eclipse_test_success_count' => 9999,
                        'action_regret_score' => 0.0,
                        'prompt_quality_delta' => 1.0,
                        'rivals_voice_multiplier' => 9.0,
                    ],
                    'generated_at' => '2026-05-19T00:00:00Z',
                ];
            }
        };
        $dogfoodStub = $this->dogfood();
        // Forja um relatório "perfeito" via inserção real (RefreshDatabase
        // garante tabela). 50 sessões success / 0 regret / dictionary ok.
        for ($i = 0; $i < 100; $i++) {
            $dogfoodStub->record([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
                'auto_event' => [
                    'final_mode' => VoxSchema::MODE_INTENT_COMPILE,
                    'launch_source' => VoxDogfoodService::SOURCE_HOTKEY,
                ],
            ]);
        }

        $gate = new VoxV7UnlockGateService($metricsStub, $dogfoodStub);
        $env = $gate->evaluate();

        // Mesmo com tudo "perfeito" via dados sintéticos, código NUNCA
        // libera V7. Doutrina V6-F. Quem destrava é ADR + humano.
        $this->assertFalse($env['unlocked'], 'V7 unlocked precisa permanecer FALSE mesmo com dados perfeitos');
        $this->assertTrue($env['requires_human_adr']);
        // `would_unlock_if_doctrine_allowed` pode até ser true (medição
        // honesta), mas isso é descrição, não permissão.
        $this->assertArrayHasKey('would_unlock_if_doctrine_allowed', $env);
    }
}
