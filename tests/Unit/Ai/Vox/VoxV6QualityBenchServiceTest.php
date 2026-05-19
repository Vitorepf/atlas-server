<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Gate\VoxV6QualityBenchService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V6-FPG-C · invariantes do quality bench.
 *
 * Esses testes existem para travar regressões na medição da qualidade V6:
 *   - schema canônico estável.
 *   - V7 NUNCA destrava aqui, mesmo com score 100%.
 *   - mobile / Voice Realtime continuam intocados (flags falsas no envelope).
 *   - corpus determinístico (2 chamadas seguidas devolvem o mesmo veredito).
 *   - quando rodado contra a base limpa do repo, o bench passa.
 */
final class VoxV6QualityBenchServiceTest extends TestCase
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

    private function bench(): VoxV6QualityBenchService
    {
        return $this->app->make(VoxV6QualityBenchService::class);
    }

    public function test_envelope_uses_canonical_schema_and_locks_v7(): void
    {
        $env = $this->bench()->build();

        $this->assertSame(VoxV6QualityBenchService::SCHEMA, $env['schema']);
        $this->assertSame('atlas.vox.v6_quality_bench.v1', $env['schema']);
        $this->assertSame('0.1.0', $env['version']);
        $this->assertFalse($env['v7_unlock_allowed'], 'V7 NUNCA pode ser true no bench');
        $this->assertFalse($env['mobile_touched']);
        $this->assertFalse($env['voice_realtime_touched']);
        $this->assertFalse($env['paid_api_required']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $env['generated_at']);
    }

    public function test_bench_is_deterministic_across_runs(): void
    {
        $a = $this->bench()->build();
        $b = $this->bench()->build();

        $this->assertSame($a['scores'], $b['scores'], 'scores devem ser idênticos em 2 rodadas');
        $this->assertSame($a['totals'], $b['totals'], 'totals devem ser idênticos');
        $this->assertSame(
            count($a['cases']),
            count($b['cases']),
            'count de cases deve ser idêntico',
        );
    }

    public function test_corpus_covers_all_required_categories(): void
    {
        $env = $this->bench()->build();
        $categories = [];
        foreach ($env['cases'] as $c) {
            $categories[$c['category']] = ($categories[$c['category']] ?? 0) + 1;
        }
        foreach (['dictation', 'polish', 'intent_compile', 'execute', 'restriction', 'dangerous'] as $cat) {
            $this->assertArrayHasKey($cat, $categories, "categoria {$cat} ausente no corpus");
            $this->assertGreaterThanOrEqual(3, $categories[$cat], "categoria {$cat} precisa de ≥3 casos");
        }
    }

    public function test_dangerous_commands_always_block(): void
    {
        $env = $this->bench()->build();
        $dangerCases = array_values(array_filter(
            $env['cases'],
            static fn (array $c): bool => $c['category'] === 'dangerous',
        ));
        $this->assertNotEmpty($dangerCases);
        foreach ($dangerCases as $c) {
            $this->assertSame(
                VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
                $c['observed']['intervention'],
                "caso {$c['id']} deveria ser disagree"
            );
            $this->assertTrue(
                $c['observed']['blocking'],
                "caso {$c['id']} deveria ser blocking=true (texto: {$c['text']})"
            );
        }
        $this->assertSame(1.0, $env['scores']['dangerous_command_safety']);
    }

    public function test_pass_on_clean_tree(): void
    {
        $env = $this->bench()->build();
        $this->assertSame(
            VoxV6QualityBenchService::STATUS_PASS,
            $env['status'],
            'bench deveria fechar PASS num tree limpo. Score: '.$env['score_overall']
                .' Cases ruins: '.implode(',', array_map(
                    static fn (array $c): string => $c['id'].'='.implode('|', $c['notes']),
                    array_values(array_filter($env['cases'], static fn ($c) => ! $c['ok'])),
                )),
        );
        $this->assertGreaterThanOrEqual(0.85, $env['score_overall']);
    }

    public function test_airpods_guidance_present_and_in_pt_br(): void
    {
        $env = $this->bench()->build();
        $this->assertNotEmpty($env['airpods_guidance_pt_br']);
        $guidance = (string) $env['airpods_guidance_pt_br'];
        $this->assertStringContainsString('AirPods', $guidance);
        // Texto PT-BR canon: aponta para Ajustes do Sistema > Som > Entrada.
        $this->assertStringContainsString('Entrada', $guidance);
        $stt = $env['stt_environment_ready'];
        $this->assertTrue($stt['uses_macos_default_input']);
        $this->assertNotEmpty($stt['input_device_selection_hint_pt_br']);
    }

    public function test_envelope_serialises_cleanly_and_no_token_leak(): void
    {
        $env = $this->bench()->build();
        $json = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertGreaterThan(2000, strlen($json));
        // Defesa em profundidade: o bench nunca deve carregar segredo/áudio.
        foreach (['Bearer', 'sk-ant-', 'sk-proj-', 'api_key', 'audio_bytes', 'raw_pcm'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $json,
                "leak detectado no envelope do bench: {$needle}"
            );
        }
    }
}
