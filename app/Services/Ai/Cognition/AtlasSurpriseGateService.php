<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailureComparisonEngine;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * T4-S2 · Surpresa medida como gate de gravação.
 *
 * A memória por HARVEST PLANO grava tudo que a sessão tocou — inclusive o que o
 * cérebro já sabia. Isto polui o candidate set G0 com redundância e afoga o pouco
 * que é informação NOVA. Este gate mede, determinísticamente e sem provider, a
 * SURPRESA de cada candidato contra a APOSTA pré-sessão (a predição barata que a
 * sessão começou com = o context pack que o hook já injetou no transcript):
 *
 *   surpresa = |tokens salientes do candidato AUSENTES da predição| / |tokens do candidato|
 *
 * surpresa ≈ 1.0 ⇒ informação que o pack NÃO carregava (a coisa mais valiosa de
 * memorizar); surpresa ≈ 0.0 ⇒ o pack já sabia (o previsto quase não grava). É a
 * mesma ideia de {@see ProductiveFailureComparisonEngine::surprisePoints()}
 * (tokens da realidade ausentes da tentativa) — reusada aqui como função pura, sem
 * arrastar as deps pesadas daquele motor (WorkedExampleSelector + delta extractor)
 * para o hot path de captura de sessão.
 *
 * SEGURANÇA / ANTI-GOODHART:
 *   - fail-open: predição vazia/curta (a sessão não começou com um pack, ou a aposta
 *     é fraca demais para julgar) ⇒ gated=false, record=true — NUNCA se perde um
 *     candidato por falta de aposta;
 *   - o threshold é um default principiado (config-override), NÃO tunado para bater
 *     um alvo de queda (ver a lição D5 quality 93→56: não se tuna para o número);
 *   - o candidato só é suprimido quando a aposta é substancial E a surpresa é
 *     claramente baixa — a suppressão é conservadora por construção.
 *
 * Read/computacional apenas. Determinístico: mesma entrada ⇒ mesma saída.
 */
final class AtlasSurpriseGateService
{
    /** Abaixo disto o candidato é "previsto" (o pack já sabia) ⇒ não grava. */
    public const DEFAULT_THRESHOLD = 0.5;

    /** Acima disto o candidato é surpresa forte ⇒ prioridade alta. */
    public const DEFAULT_HIGH_BAND = 0.75;

    /**
     * A predição precisa de ao menos N tokens salientes para ser uma aposta real.
     * Abaixo disto, não há aposta suficiente para julgar surpresa ⇒ fail-open.
     */
    public const DEFAULT_MIN_PREDICTION_TOKENS = 8;

    /**
     * Julga um candidato contra a predição pré-sessão.
     *
     * @return array{surprise:float, record:bool, priority:string, predicted:bool, gated:bool, novel_tokens:int, candidate_tokens:int}
     */
    public function evaluate(string $candidate, string $prediction, ?float $threshold = null): array
    {
        $threshold = $threshold ?? (AiValueNormalizer::finiteFloatOrNull(config('atlas.aobg.surprise_gate.threshold', self::DEFAULT_THRESHOLD)) ?? self::DEFAULT_THRESHOLD);
        $highBand = AiValueNormalizer::finiteFloatOrNull(config('atlas.aobg.surprise_gate.high_band', self::DEFAULT_HIGH_BAND)) ?? self::DEFAULT_HIGH_BAND;
        $minPredictionTokens = max(0, (int) config('atlas.aobg.surprise_gate.min_prediction_tokens', self::DEFAULT_MIN_PREDICTION_TOKENS));

        $candidateTokens = $this->tokens($candidate);
        $predictionTokens = $this->tokens($prediction);

        // Fail-open: sem aposta substancial não se gate nada (byte-idêntico a "gravar
        // tudo"). Um candidato sem tokens salientes também passa livre — nada a julgar.
        if (count($predictionTokens) < $minPredictionTokens || $candidateTokens === []) {
            return [
                'surprise' => 1.0,
                'record' => true,
                'priority' => 'normal',
                'predicted' => false,
                'gated' => false,
                'novel_tokens' => count($candidateTokens),
                'candidate_tokens' => count($candidateTokens),
            ];
        }

        $novel = array_values(array_diff($candidateTokens, $predictionTokens));
        $surprise = AiValueNormalizer::clampUnit(count($novel) / count($candidateTokens));

        $record = $surprise >= $threshold;
        $priority = ! $record ? 'low' : ($surprise >= $highBand ? 'high' : 'normal');

        return [
            'surprise' => round($surprise, 4),
            'record' => $record,
            'priority' => $priority,
            'predicted' => ! $record,
            'gated' => true,
            'novel_tokens' => count($novel),
            'candidate_tokens' => count($candidateTokens),
        ];
    }

    /**
     * Surpresa nua em [0,1] (fração de tokens novos). Conveniência para callers que só
     * querem o score; delega ao mesmo cálculo de {@see self::evaluate()}.
     */
    public function score(string $candidate, string $prediction): float
    {
        return AiValueNormalizer::finiteFloatOrNull($this->evaluate($candidate, $prediction)['surprise']) ?? 0.0;
    }

    /**
     * Tokeniza como o motor de comparação canônico: palavras alfanuméricas de ≥4
     * chars, minúsculas, únicas. Stopwords curtas/pontuação caem fora naturalmente.
     *
     * ponytail: 6 linhas duplicadas de ProductiveFailureComparisonEngine::tokens em
     * vez de acoplar dois subsistemas por uma função pura trivial.
     *
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[a-z0-9_]{4,}/', mb_strtolower($value), $matches);

        return array_values(array_unique($matches[0]));
    }
}
