<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\AiTrace;
use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAiTracesTable;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * A recusa tem de deixar MARCA no mesmo lugar onde a captura deixa.
 *
 * O bloco que recusa por `operator_text_not_declared` traz no proprio comentario a
 * promessa: "o recibo torna a lacuna CONTAVEL, para a superficie que falta aparecer como
 * numero e nao como sumico". A promessa nao era cumprida — `attachReceipt` so era chamado
 * no caminho de sucesso, e o unico caller
 * (`AiGatewayService::captureOperatorLearningFromTrace`) descarta o retorno. O recibo era
 * montado, devolvido, e evaporava.
 *
 * O efeito nao e cosmetico: sem marca, "a superficie nunca declarou o texto do operador" e
 * indistinguivel de "nao havia nada para capturar". A primeira e uma lacuna de produto que
 * alguem precisa fechar; a segunda e o sistema funcionando. Confundir as duas e como um
 * gate que nao reprova — parece verde porque nao mede.
 */
final class OperatorLearningCaptureReceiptTest extends TestCase
{
    use CreatesAiTracesTable;
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAiTracesTable();
        $this->createOperatorIntelligenceTables();
        config(['atlas_operator_intelligence.enabled' => true, 'atlas_operator_intelligence.chat_capture_enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        Schema::dropIfExists('ai_traces');
        parent::tearDown();
    }

    private function trace(): AiTrace
    {
        return AiTrace::query()->create([
            'id' => (string) Str::uuid(),
            'source_type' => 'manual',
            'status' => 'queued',
            'input_text' => 'texto qualquer',
            'metadata' => ['mode' => 'async'],
        ]);
    }

    public function test_recusa_por_texto_nao_declarado_deixa_recibo_no_trace(): void
    {
        $trace = $this->trace();

        $resultado = app(OperatorLearningRuntimeCaptureService::class)
            ->captureFromTrace($trace, 'texto qualquer', ['source_type' => 'manual', 'payload' => []]);

        $this->assertSame('skipped', $resultado['status']);
        $this->assertSame('operator_text_not_declared', $resultado['reason']);

        // A prova nao e o retorno — e o TRACE. O retorno ja existia e ninguem o lia.
        $recibo = data_get($trace->refresh()->metadata, 'operator_learning_capture');
        $this->assertIsArray($recibo, 'a recusa tem de deixar marca no trace, senao a lacuna nao e contavel');
        $this->assertSame('operator_text_not_declared', $recibo['reason']);
        $this->assertSame('skipped', $recibo['status']);
    }

    public function test_o_recibo_nao_apaga_o_resto_da_metadata_do_trace(): void
    {
        $trace = $this->trace();

        app(OperatorLearningRuntimeCaptureService::class)
            ->captureFromTrace($trace, 'x', ['source_type' => 'manual', 'payload' => []]);

        $this->assertSame('async', data_get($trace->refresh()->metadata, 'mode'), 'o recibo entra ao lado, nao no lugar');
    }

    public function test_trace_que_nunca_foi_candidato_nao_ganha_recibo(): void
    {
        $trace = AiTrace::query()->create([
            'id' => (string) Str::uuid(),
            'source_type' => 'agent_internal',
            'status' => 'queued',
            'input_text' => 'ruido do proprio Atlas',
            'metadata' => ['mode' => 'async'],
        ]);

        app(OperatorLearningRuntimeCaptureService::class)
            ->captureFromTrace($trace, 'x', ['source_type' => 'agent_internal', 'payload' => ['operator_text' => 'oi']]);

        // A linha que separa LACUNA de "nunca foi candidato". Marcar todo trace de agente
        // encheria a metadata da maioria das interacoes com um aviso que nao descreve
        // defeito nenhum — e um recibo que aparece sempre nao e sinal, e fundo.
        $this->assertNull(data_get($trace->refresh()->metadata, 'operator_learning_capture'));
    }
}
