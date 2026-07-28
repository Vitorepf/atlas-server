<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\AtlasOpenBrainSessionCaptureService;
use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use ReflectionClass;
use Tests\TestCase;

/**
 * T4-S2 — surpresa medida como gate de gravação.
 *
 * Prova o MECANISMO (não uma medição de 30d, que se acumula em runtime):
 *   - candidato surpreendente (tokens ausentes da aposta) → grava com prioridade;
 *   - candidato previsto (coberto pela aposta) → suprime ("o previsto quase não grava");
 *   - fail-open: sem aposta substancial, gating desliga e tudo passa;
 *   - a captura extrai a APOSTA (texto AOBG) do transcript e a compõe com o gate.
 */
final class AtlasSurpriseGateServiceTest extends TestCase
{
    private function gate(): AtlasSurpriseGateService
    {
        return new AtlasSurpriseGateService;
    }

    /** Aposta substancial cobrindo os 4 tokens do candidato ⇒ previsto ⇒ suprime. */
    public function test_predicted_candidate_is_suppressed(): void
    {
        $prediction = 'retrieval query candidate registry services autonomousevolution recall vector semantic hybrid';
        $verdict = $this->gate()->evaluate('retrieval query candidate registry recall', $prediction);

        $this->assertTrue($verdict['gated'], 'aposta substancial ⇒ gating ativo');
        $this->assertFalse($verdict['record'], 'candidato previsto não grava');
        $this->assertTrue($verdict['predicted']);
        $this->assertSame('low', $verdict['priority']);
        $this->assertLessThan(0.5, $verdict['surprise']);
    }

    /** Tokens ausentes da aposta ⇒ surpresa forte ⇒ grava com prioridade alta. */
    public function test_surprising_candidate_is_recorded_high_priority(): void
    {
        $prediction = 'retrieval query candidate registry services autonomousevolution recall vector semantic hybrid';
        $verdict = $this->gate()->evaluate('mitochondria powerhouse ribosome flagellum', $prediction);

        $this->assertTrue($verdict['gated']);
        $this->assertTrue($verdict['record'], 'informação nova grava');
        $this->assertFalse($verdict['predicted']);
        $this->assertSame('high', $verdict['priority']);
        $this->assertEqualsWithDelta(1.0, $verdict['surprise'], 0.001);
    }

    /** Sem aposta (ou aposta fraca) ⇒ fail-open: gating desligado, tudo grava. */
    public function test_fail_open_when_no_bet(): void
    {
        foreach (['', 'tiny bet here'] as $weakPrediction) {
            $verdict = $this->gate()->evaluate('anything at all novel or not', $weakPrediction);
            $this->assertFalse($verdict['gated'], "aposta fraca ('{$weakPrediction}') não gate");
            $this->assertTrue($verdict['record'], 'fail-open nunca perde candidato');
        }
    }

    /** Candidato sem tokens salientes não trava e não quebra (divisão por zero). */
    public function test_empty_candidate_is_safe(): void
    {
        $verdict = $this->gate()->evaluate('a b c', 'retrieval query candidate registry recall vector semantic hybrid extra');
        $this->assertTrue($verdict['record']);
        $this->assertFalse($verdict['gated']);
    }

    /** score() é a mesma surpresa, sempre em [0,1]. */
    public function test_score_is_bounded(): void
    {
        $prediction = 'retrieval query candidate registry recall vector semantic hybrid extra';
        $s = $this->gate()->score('mitochondria ribosome', $prediction);
        $this->assertGreaterThanOrEqual(0.0, $s);
        $this->assertLessThanOrEqual(1.0, $s);
    }

    /**
     * Composição end-to-end sem DB: a captura EXTRAI a aposta (texto marcado (AOBG))
     * do transcript e destila os learnings; o gate então suprime o previsto e grava o
     * surpreendente. Prova extração + scoring juntos (o glue de partição em
     * captureSession é o skip trivial de um candidato suprimido).
     */
    public function test_capture_extracts_bet_and_gate_partitions(): void
    {
        $lines = [
            ['text' => "# Atlas Open Brain Context Pack (AOBG)\nmódulos quentes retrieval query candidate registry services autonomousevolution recall vector semantic hybrid"],
            ['text' => 'ATLAS-LEARNING: retrieval query candidate registry recall retrieval://registry'],
            ['text' => 'ATLAS-LEARNING: mitochondria powerhouse ribosome flagellum biology://cell'],
        ];

        // `newInstanceWithoutConstructor()` pulava o construtor para alcancar o metodo
        // privado `distil()`. Funcionava enquanto `distil()` nao tocasse dependencia
        // injetada — e parou de funcionar quando ele passou a chamar
        // `$this->injectionBoundaryClassifier` (linha 490 do service). O erro era
        // "Typed property ... must not be accessed before initialization": o teste
        // quebrou por causa da PROPRIA tecnica, nao do comportamento sob teste.
        //
        // O container resolve as quatro dependencias, entao nao ha motivo para burlar o
        // construtor. A reflexao fica so no que ela e mesmo necessaria: alcancar o metodo
        // privado.
        $svc = app(AtlasOpenBrainSessionCaptureService::class);
        $ref = new ReflectionClass(AtlasOpenBrainSessionCaptureService::class);
        $distil = $ref->getMethod('distil');
        $distil->setAccessible(true);
        /** @var array{prediction:string,learnings:list<array{summary:string,claim:string}>} $d */
        $d = $distil->invoke($svc, $lines, []);

        $this->assertStringContainsString('retrieval', $d['prediction'], 'aposta capturou o pack');
        $this->assertStringContainsString('candidate', $d['prediction']);
        $this->assertCount(2, $d['learnings'], 'dois learnings destilados');

        $gate = $this->gate();
        $verdicts = [];
        foreach ($d['learnings'] as $l) {
            $verdicts[] = $gate->evaluate(trim(($l['claim'] ?? '').' '.$l['summary']), $d['prediction'])['record'];
        }

        // Exatamente um sobrevive: o surpreendente. O previsto é suprimido.
        $this->assertContains(true, $verdicts, 'o surpreendente grava');
        $this->assertContains(false, $verdicts, 'o previsto é suprimido');
        $this->assertSame(1, count(array_filter($verdicts)), 'só o surpreendente passa');
    }
}
