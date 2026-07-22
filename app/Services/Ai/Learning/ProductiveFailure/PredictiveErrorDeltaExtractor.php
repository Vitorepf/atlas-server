<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

class PredictiveErrorDeltaExtractor
{
    /**
     * @return array<string,mixed>
     */
    public function extract(string $operatorPrediction, string $validatedReality, ?string $operatorDelta = null): array
    {
        $operatorPrediction = trim($operatorPrediction);
        $validatedReality = trim($validatedReality);
        $operatorDelta = trim((string) $operatorDelta);

        if ($operatorDelta !== '') {
            $delta = $operatorDelta;
        } else {
            $predictionTokens = $this->tokens($operatorPrediction);
            $realityTokens = $this->tokens($validatedReality);
            $missing = array_values(array_diff($realityTokens, $predictionTokens));
            $extra = array_values(array_diff($predictionTokens, $realityTokens));

            $delta = trim(sprintf(
                'Divergencia principal: realidade enfatiza [%s]; tentativa enfatizou [%s].',
                implode(', ', array_slice($missing, 0, 8)) ?: 'nenhum termo novo claro',
                implode(', ', array_slice($extra, 0, 8)) ?: 'nenhum termo extra claro',
            ));
        }

        return [
            'schema_version' => 'atlas.cognitive.productive_failure.prediction_error_delta.v1',
            'status' => $delta !== '' ? 'ok' : 'blocked',
            'prediction_error_delta' => $delta,
            'signal_strength' => $this->signalStrength($operatorPrediction, $validatedReality),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[a-zA-Z0-9_]{4,}/', strtolower($value), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function signalStrength(string $prediction, string $reality): string
    {
        $predictionTokens = $this->tokens($prediction);
        $realityTokens = $this->tokens($reality);

        if ($predictionTokens === [] || $realityTokens === []) {
            return 'low';
        }

        $overlap = count(array_intersect($predictionTokens, $realityTokens)) / max(1, count($realityTokens));

        return match (true) {
            $overlap >= 0.65 => 'low_delta',
            $overlap >= 0.30 => 'medium_delta',
            default => 'high_delta',
        };
    }
}
