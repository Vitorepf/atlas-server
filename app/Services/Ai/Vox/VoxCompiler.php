<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use Illuminate\Support\Str;

/**
 * Compiles a validated VoxTranscript into a VoxIntentPacket.v1.
 *
 * V0 scope (Onda 2 / Claude C): mode=dictation only. The compiler produces
 * a packet where `compiled_prompt` and `compiled_prompt_template` are
 * intentionally null — V0 is dictation, no prompt synthesis. Higher modes
 * are wired here when V1+ ships and are intentionally absent.
 *
 * Invariants enforced (per `docs/contracts/vox/VoxIntentPacket.v1.md`):
 *   - dictation => compiled_prompt MUST be null
 *   - dictation => goal is the empty string
 *   - dictation => constraints is the empty array
 *   - dictation => context_refs contains exactly one `{ kind: "none", ... }` item
 *   - risk_class comes from VoxRiskClassifier (R0 for dictation)
 *   - human_input_text is the operator's transcript verbatim
 */
final class VoxCompiler
{
    public function __construct(
        private readonly VoxRiskClassifier $riskClassifier,
    ) {}

    /**
     * @param  array<string,mixed>  $transcript  Validated VoxTranscript payload.
     * @return array<string,mixed> VoxIntentPacket.v1
     */
    public function compile(array $transcript, string $mode): array
    {
        if ($mode !== VoxSchema::MODE_DICTATION) {
            throw new \LogicException(
                "VoxCompiler V0 only supports mode=dictation; got: {$mode}"
            );
        }

        $risk = $this->riskClassifier->classify($transcript, $mode);
        $humanInput = (string) ($transcript['text'] ?? '');

        return [
            'schema' => VoxSchema::INTENT_PACKET,
            'session_id' => (string) $transcript['session_id'],
            'intent_id' => (string) Str::uuid(),
            'transcript_ref' => (string) $transcript['transcript_id'],
            'mode' => VoxSchema::MODE_DICTATION,
            'goal' => '',
            'constraints' => [],
            'context_refs' => [
                ['kind' => 'none', 'ref' => null, 'resolved' => true],
            ],
            'provider_hint' => 'local',
            'executor_hint' => 'none',
            'output_format' => 'text',
            'risk_class' => $risk['risk_class'],
            'risk_reasoning' => $risk['risk_reasoning'],
            'human_input_text' => $humanInput,
            'compiled_prompt' => null,
            'compiled_prompt_template' => null,
            'discordance_hint' => null,
            'memory_candidate' => null,
            'compiler_version' => VoxSchema::COMPILER_VERSION,
        ];
    }
}
