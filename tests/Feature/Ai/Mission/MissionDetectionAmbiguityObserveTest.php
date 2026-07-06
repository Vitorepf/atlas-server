<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionDetectionService;
use Tests\TestCase;

/**
 * Observe-wire: DanglingActionVerbScorer + PromptStructuralSparsityScorer →
 * campo novo 'ambiguity' do MissionSignal->toArray() produzido por
 * MissionDetectionService::detect().
 */
class MissionDetectionAmbiguityObserveTest extends TestCase
{
    private function service(): MissionDetectionService
    {
        return app(MissionDetectionService::class);
    }

    public function test_verbo_solto_pontua_dangling_maximo_e_esparsidade_de_palavra_unica(): void
    {
        $signal = $this->service()->detect('crie');

        $this->assertSame(1.0, $signal->ambiguity['dangling_action_verb']);
        $this->assertSame(0.6, $signal->ambiguity['structural_sparsity']);

        $array = $signal->toArray();
        $this->assertSame(
            ['dangling_action_verb' => 1.0, 'structural_sparsity' => 0.6],
            $array['ambiguity'],
        );
    }

    public function test_prompt_aterrado_pontua_zero_e_nao_altera_deteccao_existente(): void
    {
        $signal = $this->service()->detect('Implementar exporter CSV no painel admin de vendas hoje');

        $this->assertSame(0.0, $signal->ambiguity['dangling_action_verb']);
        $this->assertSame(0.0, $signal->ambiguity['structural_sparsity']);

        // Observe-only: campos de veredito pré-existentes seguem intactos.
        $this->assertFalse($signal->shouldActivateMissionMode);
        $this->assertSame([], $signal->persistenceKeywords);
        $this->assertSame([], $signal->obraKeywords);
    }

    public function test_scores_ficam_na_banda_valida_para_prompt_com_reticencias(): void
    {
        $signal = $this->service()->detect('melhore tudo...');

        foreach ($signal->ambiguity as $field => $score) {
            $this->assertNotNull($score, "score '{$field}' deve ser computado, não null");
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }

        $this->assertSame(0.5, $signal->ambiguity['structural_sparsity'], 'reticências finais pontuam 0.5');
    }
}
