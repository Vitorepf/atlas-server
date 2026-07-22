<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleSelector;

class ProductiveFailureComparisonEngine
{
    public function __construct(
        private readonly WorkedExampleSelector $examples,
        private readonly PredictiveErrorDeltaExtractor $delta,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function compare(array $session, ?string $validatedReality = null, ?string $operatorDelta = null): array
    {
        $selection = $this->examples->select(
            (string) ($session['knowledge_node_id'] ?? ''),
            (string) ($session['domain'] ?? 'learning'),
        );
        $example = (array) ($selection['selected'] ?? []);
        $reality = trim((string) $validatedReality);

        if ($reality === '') {
            $reality = $this->validatedRealityFromExample($example, (array) ($session['phase_1_problem'] ?? []));
        }

        $attempt = (array) ($session['phase_1_attempt'] ?? []);
        $delta = $this->delta->extract(
            (string) ($attempt['operator_prediction'] ?? ''),
            $reality,
            $operatorDelta,
        );

        return [
            'schema_version' => 'atlas.cognitive.productive_failure.comparison.v1',
            'status' => ($delta['status'] ?? null) === 'ok' ? 'comparison_ready' : 'blocked',
            'worked_example_id' => $example['id'] ?? null,
            'worked_example_source' => $example['source'] ?? ($example ? 'unknown' : 'fallback_minimal_canonical'),
            'validated_reality' => $reality,
            'prediction_error_delta' => $delta['prediction_error_delta'] ?? '',
            'what_matched' => $this->intersectionSummary((string) ($attempt['solution_attempt'] ?? ''), $reality),
            'what_diverged' => $delta['prediction_error_delta'] ?? '',
            'surprise_points' => $this->surprisePoints($reality, (string) ($attempt['solution_attempt'] ?? '')),
            'selection' => $selection,
        ];
    }

    /**
     * @param  array<string,mixed>  $example
     * @param  array<string,mixed>  $problem
     */
    private function validatedRealityFromExample(array $example, array $problem): string
    {
        $steps = (array) ($example['solution_full'] ?? []);
        $parts = [];

        foreach ($steps as $step) {
            if (is_array($step)) {
                $parts[] = trim(implode(' ', array_filter([
                    $step['action'] ?? null,
                    $step['reasoning'] ?? null,
                    $step['why_works'] ?? null,
                ])));
            }
        }

        $fallback = 'Realidade validada minima: compare sua hipotese com a evidencia verificavel, procure a primeira metrica que invalida sua previsao, e extraia o principio transferivel.';

        return trim(implode(' ', array_filter($parts))) ?: (string) ($problem['validated_reality_hint'] ?? $fallback);
    }

    private function intersectionSummary(string $attempt, string $reality): string
    {
        $attemptWords = $this->tokens($attempt);
        $realityWords = $this->tokens($reality);
        $matched = array_values(array_intersect($attemptWords, $realityWords));

        return $matched === []
            ? 'Nenhum alinhamento textual forte; revisar raciocinio estruturalmente.'
            : 'Alinhou em: '.implode(', ', array_slice($matched, 0, 8)).'.';
    }

    /**
     * @return array<int,string>
     */
    private function surprisePoints(string $reality, string $attempt): array
    {
        $points = array_values(array_diff($this->tokens($reality), $this->tokens($attempt)));

        return array_slice($points, 0, 6);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[a-zA-Z0-9_]{4,}/', strtolower($value), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
