<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Maps a VoxTranscript+mode to an initial risk class (R0..R4) per the
 * canonical table in `docs/contracts/vox/VoxIntentPacket.v1.md`.
 *
 * V0 contract: only `dictation` is supported by the Kernel, and dictation
 * is always R0 (sem efeito externo). Kernel may reclassify R upward later
 * (V2+), never downward. For V0 we return R0 for dictation and refuse to
 * classify anything else — that refusal becomes a Kernel-level rejection,
 * not a silent downgrade.
 */
final class VoxRiskClassifier
{
    /**
     * @param  array<string,mixed>  $transcript  Validated VoxTranscript payload.
     * @return array{risk_class: string, risk_reasoning: string}
     */
    public function classify(array $transcript, string $mode): array
    {
        if ($mode !== VoxSchema::MODE_DICTATION) {
            // V0 surface — only dictation is wired to a compiled outcome.
            // The controller validates this earlier; the classifier is the
            // last line of defense.
            throw new \LogicException(
                "VoxRiskClassifier V0 only supports mode=dictation; got: {$mode}"
            );
        }

        return [
            'risk_class' => VoxSchema::RISK_R0,
            'risk_reasoning' => 'dictation pura: texto vai para clipboard/campo focado, zero efeito externo',
        ];
    }
}
