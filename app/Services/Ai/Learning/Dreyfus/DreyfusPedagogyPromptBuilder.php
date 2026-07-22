<?php

namespace App\Services\Ai\Cognitive\Dreyfus;

class DreyfusPedagogyPromptBuilder
{
    /**
     * @param  array<string,mixed>  $resolution
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function build(array $resolution, array $context = []): array
    {
        $stage = max(1, min(5, (int) ($resolution['dreyfus_stage_resolved'] ?? 1)));
        $mode = (string) ($resolution['pedagogy_mode_resolved'] ?? $this->mode($stage));
        $topic = trim((string) ($context['topic'] ?? $context['objective'] ?? ''));

        return [
            'schema_version' => 'atlas.cognitive.dreyfus_prompt_builder.v1',
            'stage' => $stage,
            'mode' => $mode,
            'topic' => $topic,
            'system_instruction' => $this->systemInstruction($stage, $mode),
            'operator_instruction' => $this->operatorInstruction($stage, $topic),
            'output_shape' => $this->outputShape($stage),
            'quality_bar' => $this->qualityBar($stage),
            'evidence_requirements' => [
                'must_emit_stage' => true,
                'must_name_assumptions' => $stage >= 3,
                'must_request_transfer_proof' => $stage >= 3,
                'must_offer_dispute_path' => true,
            ],
        ];
    }

    private function systemInstruction(int $stage, string $mode): string
    {
        return match ($stage) {
            1 => "Use Dreyfus mode {$mode}: teach with explicit rules, glossary first, one action per step, and frequent checkpoints.",
            2 => "Use Dreyfus mode {$mode}: start from worked examples, name common traps, then give a short exercise with immediate feedback.",
            3 => "Use Dreyfus mode {$mode}: use realistic scenarios, force tradeoff comparison, and ask the operator to justify choices.",
            4 => "Use Dreyfus mode {$mode}: skip generic tutorial, present an ill-structured case, require metrics first, and give adversarial feedback.",
            default => "Use Dreyfus mode {$mode}: compress principles, test transfer across domains, and challenge the operator with frontier ambiguity.",
        };
    }

    private function operatorInstruction(int $stage, string $topic): string
    {
        $subject = $topic !== '' ? " sobre {$topic}" : '';

        return match ($stage) {
            1 => "Monte uma sequencia guiada{$subject}: vocabulario, regra, exemplo minimo, checkpoint.",
            2 => "Monte uma pratica curta{$subject}: exemplo trabalhado, variacao simples, feedback imediato.",
            3 => "Monte um cenario aplicado{$subject}: alternativas, criterios de decisao e rubrica de dominio.",
            4 => "Monte um caso ambiguo{$subject}: sintomas parciais, primeira metrica, hipotese e refutacao.",
            default => "Monte um desafio de transferencia{$subject}: principio, compressao, analogia distante e prova de dominio.",
        };
    }

    /**
     * @return array<int,string>
     */
    private function outputShape(int $stage): array
    {
        return match ($stage) {
            1 => ['glossary', 'rule', 'minimal_example', 'checkpoint'],
            2 => ['worked_example', 'common_traps', 'exercise', 'feedback_key'],
            3 => ['scenario', 'tradeoffs', 'operator_rationale_prompt', 'mastery_rubric'],
            4 => ['ill_structured_case', 'metric_first_question', 'adversarial_review', 'next_probe'],
            default => ['frontier_case', 'principle_compression', 'cross_domain_transfer', 'teach_back'],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityBar(int $stage): array
    {
        return [
            'no_claimed_mastery_without_evidence' => true,
            'no_clinical_claims' => true,
            'scaffolding_density' => match ($stage) {
                1 => 'high',
                2 => 'medium_high',
                3 => 'medium',
                default => 'low',
            },
            'ambiguity_level' => match ($stage) {
                1 => 'very_low',
                2 => 'low',
                3 => 'medium',
                4 => 'high',
                default => 'frontier',
            },
        ];
    }

    private function mode(int $stage): string
    {
        return match ($stage) {
            1 => 'novato',
            2 => 'iniciante_avancado',
            3 => 'competente',
            4 => 'expert',
            default => 'master',
        };
    }
}
