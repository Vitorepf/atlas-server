<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class ProductiveFailureProblemSelector
{
    public function __construct(
        private readonly DreyfusOverlayRepository $nodes,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function select(string $topic, string $domain, int $dreyfusStage): array
    {
        return $this->slo->measure('cognitive.productive_failure.problem_selection', function () use ($topic, $domain, $dreyfusStage): array {
            $topic = trim($topic) !== '' ? trim($topic) : 'unknown';
            $stage = max(1, min(5, $dreyfusStage));
            $difficulty = max($stage, min(5, $stage + 1));

            return [
                'schema_version' => 'atlas.cognitive.productive_failure.problem.v1',
                'status' => 'selected',
                'knowledge_node_id' => $this->nodes->nodeIdForTopic($topic),
                'topic' => $topic,
                'domain' => $domain,
                'prediction_prompt' => $this->promptFor($topic, $domain),
                'context' => [
                    'instruction' => 'Responda antes de consultar teoria, exemplo, provider ou busca externa.',
                    'target_minutes' => $stage >= 4 ? 15 : 10,
                    'success_shape' => 'hipotese inicial + primeiro teste verificavel + risco que voce pode estar ignorando',
                ],
                'expected_difficulty' => $difficulty,
                'source' => 'canonical_library_minimal',
                'calibration' => [
                    'dreyfus_stage_target' => $stage,
                    'productive_failure_band' => [$stage, min(5, $stage + 2)],
                    'reason' => 'ligeiramente_acima_do_nivel_atual_para_gerar_erro_preditivo_calibrado',
                ],
            ];
        }, [
            'domain' => $domain,
            'surface_id' => 'atlas_productive_failure',
        ]);
    }

    private function promptFor(string $topic, string $domain): string
    {
        return match ($domain) {
            'programming' => "Voce precisa resolver '{$topic}' em um sistema real. Sem tutorial: qual hipotese voce testaria primeiro, qual evidencia confirmaria essa hipotese, e qual alternativa pode derrubar sua intuicao?",
            'marketing' => "Voce precisa melhorar '{$topic}' sem benchmark pronto. Sem exemplos: qual mecanismo causaria o ganho, qual metrica provaria isso, e qual vies pode estar enganando sua leitura?",
            'finance' => "Voce precisa avaliar '{$topic}' sob risco real. Sem consultar tese externa: qual premissa sustenta a decisao, qual evidencia invalida a premissa, e qual perda maxima voce aceitaria?",
            default => "Antes de estudar '{$topic}', gere sua melhor solucao inicial: qual e sua previsao, qual passo verificavel voce executaria primeiro, e que sinal mostraria que sua previsao estava errada?",
        };
    }
}
