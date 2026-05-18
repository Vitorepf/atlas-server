<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Maps a VoxTranscript+mode to an initial risk class (R0..R4) per the
 * canonical table in `docs/contracts/vox/VoxIntentPacket.v1.md`.
 *
 * Modes wired:
 *   - Onda 2 (V0) `dictation`      → R0 (texto puro p/ clipboard).
 *   - Onda 4 (V1) `prompt_polish`  → R0 (polish local).
 *   - Onda 5 (V2) `intent_compile` → depende do conteúdo da fala. O
 *     extractor (VoxIntentExtractor) faz a classificação fina (R0..R4)
 *     porque precisa do texto polido + heurísticas; aqui mantemos a
 *     porta de entrada e respeitamos uma classificação prévia se a
 *     caller já tiver feito (Kernel pode reclassificar para CIMA, nunca
 *     para BAIXO).
 *
 * `governed_execute` (V3) continua proibido nesta onda.
 */
final class VoxRiskClassifier
{
    /**
     * @param  array<string,mixed>  $transcript  Validated VoxTranscript payload.
     * @param  array<string,mixed>  $signals     Optional pre-computed signals
     *        from VoxIntentExtractor: { risk_class?, risk_reasoning? }.
     * @return array{risk_class: string, risk_reasoning: string}
     */
    public function classify(array $transcript, string $mode, array $signals = []): array
    {
        return match ($mode) {
            VoxSchema::MODE_DICTATION => [
                'risk_class' => VoxSchema::RISK_R0,
                'risk_reasoning' => 'dictation pura: texto vai para clipboard/campo focado, zero efeito externo',
            ],
            VoxSchema::MODE_PROMPT_POLISH => [
                'risk_class' => VoxSchema::RISK_R0,
                'risk_reasoning' => 'prompt polish local: texto melhorado para clipboard/campo focado, zero efeito externo',
            ],
            // Both intent_compile (V2) and governed_execute (V3) feed the same
            // extractor-driven heuristic. V3 differs from V2 only in what
            // happens AFTER classification: V2 stops at the receipt, V3 may
            // route to a real executor after VoxConfirmationService + Gate.
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE => $this->classifyIntentCompile($signals),
            default => throw new \LogicException(
                "VoxRiskClassifier supports only dictation, prompt_polish, intent_compile and governed_execute; got: {$mode}"
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{risk_class: string, risk_reasoning: string}
     */
    private function classifyIntentCompile(array $signals): array
    {
        $valid = [
            VoxSchema::RISK_R0,
            VoxSchema::RISK_R1,
            VoxSchema::RISK_R2,
            VoxSchema::RISK_R3,
            VoxSchema::RISK_R4,
        ];
        $candidate = is_string($signals['risk_class'] ?? null) ? $signals['risk_class'] : null;
        if ($candidate !== null && in_array($candidate, $valid, true)) {
            $reason = is_string($signals['risk_reasoning'] ?? null) && $signals['risk_reasoning'] !== ''
                ? $signals['risk_reasoning']
                : 'classificação herdada do VoxIntentExtractor';

            return ['risk_class' => $candidate, 'risk_reasoning' => $reason];
        }

        // No signals supplied — default to the most permissive R0; the
        // extractor is expected to refine. We never default to R4 (that
        // would mute the operator) nor invent a high-risk reasoning.
        return [
            'risk_class' => VoxSchema::RISK_R0,
            'risk_reasoning' => 'intent_compile sem sinais finos: defaulting para R0 (texto/prompt local)',
        ];
    }
}
