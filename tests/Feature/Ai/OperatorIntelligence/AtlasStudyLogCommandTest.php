<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * A rede do primeiro produtor declarado de `operator_text`.
 *
 * Antes deste comando o orgao de aprendizado era mudo por construcao: o unico caller de
 * `captureFromTrace` e o gateway, entao registrar uma decisao custava uma chamada de
 * provider. Pior, `OperatorLearningRuntimeCaptureService` recusa tudo que nao declare
 * `operator_text` — guarda deliberada, posta depois de o detector ter aprendido o
 * PREAMBULO de um prompt de subagente como se fosse padrao do operador.
 *
 * O que este teste protege, e cada caso ja falhou de verdade neste corpus:
 *  - as tres guardas de entrada, porque um registro sem PRINCIPIO e diario, nao estudo;
 *  - `confidence` como eixo, porque chutar e acertar nao e aprender — sem separar os dois
 *    "acertou", o segundo vira ruido que sobe a metrica;
 *  - o caso "range larga", que e a regressao que apagou o primeiro registro real do
 *    operador: `rg` casava dentro de "la_rg_a", a privacidade subia para `sensitive` e
 *    `redactIfSensitive` trocava o texto duravel por um hash. O principio sumia do banco.
 */
final class AtlasStudyLogCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        config([
            'atlas_operator_intelligence.default_operator_id' => 'vitor',
            'atlas_operator_intelligence.shadow_mode' => true,
            'atlas_operator_intelligence.auto_apply_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    public function test_recusa_quando_falta_o_principio(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'BTN abre, eu no BB',
            '--decision' => 'call',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count(), 'registro sem principio nao pode entrar no corpus');
    }

    public function test_recusa_confianca_fora_do_eixo(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb',
            '--decision' => 'fold',
            '--why' => 'a range dele nessa posicao domina meu holding',
            '--confidence' => 'talvez',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count());
    }

    public function test_decisao_declarada_vira_sinal_com_operator_text_declared(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb, eu no BB com KJo, 40bb efetivo',
            '--decision' => 'call',
            '--why' => 'contra o open dele nessa posicao o KJo tem equity suficiente fechando a acao',
            '--confidence' => 'principled',
            '--domain' => 'poker',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // O campo exato que `OperatorPatternDetector:77` exige para minerar padrao.
        // Sem ele o sinal entra e nunca e lido — producao sem consumidor.
        $this->assertTrue((bool) data_get($signal->metadata, 'operator_text_declared'));
        $this->assertTrue((bool) data_get($signal->metadata, 'commitment_before_outcome'));
        $this->assertSame('domain', $signal->scope_type);
        $this->assertSame('poker', $signal->scope_id);
        $this->assertSame(0.9, round((float) $signal->confidence, 2));
    }

    public function test_chute_entra_com_peso_baixo(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'board 9h7h2c, ele check-raise',
            '--decision' => 'fold',
            '--why' => 'senti que ele tinha o flush draw completado',
            '--confidence' => 'guess',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // Um palpite que acertou nao pode pesar como regra do operador.
        $this->assertSame(0.25, round((float) $signal->confidence, 2));
        $this->assertSame('guess', data_get($signal->metadata, 'confidence_kind'));
    }

    public function test_principio_com_range_larga_sobrevive_ao_banco(): void
    {
        $principio = 'contra open range larga de CO eu defendo mais amplo no BB';

        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb',
            '--decision' => 'defend',
            '--why' => $principio,
            '--confidence' => 'principled',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // A prova e o TEXTO, nao o rotulo: `sensitive` dispara redacao, e o que se perde
        // e justamente o principio que o corpus existe para guardar.
        $this->assertSame($principio, $signal->normalized_claim, 'o principio nao pode virar hash a caminho do banco');
        $this->assertSame('normal', $signal->privacy_class);
        $this->assertFalse((bool) data_get($signal->metadata, 'redacted_at_rest'));
    }
}
