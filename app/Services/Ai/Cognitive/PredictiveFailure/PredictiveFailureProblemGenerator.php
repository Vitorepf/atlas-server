<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

class PredictiveFailureProblemGenerator
{
    /**
     * @param  array<string,mixed>  $selection
     * @param  array<string,mixed>  $estimate
     * @return array<string,mixed>
     */
    public function generate(array $selection, array $estimate): array
    {
        $topic = (string) ($selection['topic'] ?? 'unknown');
        $domain = (string) ($selection['domain'] ?? 'learning');
        $sourceType = (string) ($selection['source_type'] ?? 'canonical_library');

        return [
            'schema_version' => 'atlas.cognitive.predictive_failure.problem.v1',
            'description' => "Resolva um caso novo de {$topic} antes de consultar teoria ou exemplo.",
            'prediction_prompt' => 'Declare sua solucao inicial, o risco principal e a suposicao que mais pode quebrar.',
            'context' => [
                'domain' => $domain,
                'topic' => $topic,
                'mode' => 'generation_before_instruction',
                'predicted_failure_signature_key' => $selection['predicted_failure_signature_key'] ?? null,
            ],
            'expected_difficulty' => $this->difficulty((float) ($estimate['predicted_failure_probability'] ?? 0.75)),
            'source' => $sourceType,
            'privacy_class' => 'p1',
            'operator_instruction' => 'Tente errar de forma informativa; depois registre outcome para calibrar o Atlas.',
        ];
    }

    private function difficulty(float $probability): int
    {
        return match (true) {
            $probability >= 0.85 => 5,
            $probability >= 0.78 => 4,
            $probability >= 0.70 => 3,
            default => 2,
        };
    }
}
