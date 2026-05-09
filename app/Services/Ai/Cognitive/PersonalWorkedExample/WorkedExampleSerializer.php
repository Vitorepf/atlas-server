<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

class WorkedExampleSerializer
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function serialize(array $candidate): array
    {
        $steps = array_values(array_filter((array) ($candidate['raw_steps'] ?? []), 'is_array'));
        if ($steps === []) {
            $steps = $this->defaultSteps($candidate);
        }

        return [
            'schema_version' => 'atlas.cognitive.personal_worked_example_serialized.v1',
            'topic' => (string) ($candidate['topic'] ?? 'personal.example'),
            'domain' => (string) ($candidate['domain'] ?? 'learning'),
            'title' => (string) ($candidate['title'] ?? 'Personal Worked Example'),
            'problem_context' => (string) ($candidate['problem_context'] ?? $candidate['content'] ?? ''),
            'solution_full' => $this->normalizeSteps($steps),
            'fading_levels' => [
                '1' => [1, 2, 3, 4, 5],
                '2' => [1, 3, 5],
                '3' => [1, 5],
                '4' => [],
                '5' => [],
            ],
            'source' => 'personal_ledger',
            'privacy_class' => (int) ($candidate['privacy_class'] ?? 2),
            'redaction_applied' => (array) ($candidate['redaction_applied'] ?? []),
            'source_metadata' => (array) ($candidate['source_metadata'] ?? []),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $steps
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSteps(array $steps): array
    {
        return array_values(array_map(function (array $step, int $index): array {
            return [
                'step' => (int) ($step['step'] ?? $index + 1),
                'action' => (string) ($step['action'] ?? 'Execute o passo observado no ledger pessoal.'),
                'reasoning' => (string) ($step['reasoning'] ?? 'O exemplo veio de evidencia real do operador.'),
                'why_works' => (string) ($step['why_works'] ?? 'Mantem aprendizado ancorado em trabalho real.'),
            ];
        }, $steps, array_keys($steps)));
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<int,array<string,string|int>>
     */
    private function defaultSteps(array $candidate): array
    {
        $topic = (string) ($candidate['topic'] ?? 'personal.example');

        return [
            ['step' => 1, 'action' => 'Identifique o problema real em '.$topic.'.', 'reasoning' => 'Exemplo pessoal comeca no contexto concreto.', 'why_works' => 'Evita teoria solta.'],
            ['step' => 2, 'action' => 'Nomeie restricoes, risco e evidencia usada.', 'reasoning' => 'A decisao boa deixa rastro auditavel.', 'why_works' => 'Ajuda transferencia futura.'],
            ['step' => 3, 'action' => 'Execute o menor passo verificavel.', 'reasoning' => 'Feedback curto reduz erro caro.', 'why_works' => 'Transforma pratica em evidencia.'],
            ['step' => 4, 'action' => 'Compare resultado com expectativa inicial.', 'reasoning' => 'Comparacao explicita cria erro preditivo.', 'why_works' => 'Acelera consolidacao.'],
            ['step' => 5, 'action' => 'Extraia um principio reutilizavel.', 'reasoning' => 'O exemplo vira capital cognitivo.', 'why_works' => 'Permite reuso cross-domain.'],
        ];
    }
}
