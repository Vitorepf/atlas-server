<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use Illuminate\Support\Str;

/**
 * Compiles a validated VoxTranscript into a VoxIntentPacket.v1.
 *
 * Modes wired:
 *   - V0 dictation (Onda 2): compiled_prompt = null, goal = "",
 *     constraints = [], provider_hint = local.
 *   - V1 prompt_polish (Onda 4): compiled_prompt = polished text,
 *     compiled_prompt_template = builtin polisher id, goal/constraints/
 *     provider_hint inferred deterministically by VoxPromptPolisher.
 *   - V2 intent_compile (Onda 5): VoxIntentExtractor extracts goal,
 *     constraints, provider/executor hints, output_format and risk
 *     (R0..R4); VoxPromptCompiler then composes a per-provider/per-format
 *     compiled_prompt. NO provider/LLM is ever called — Lei 0.75.
 *
 * Invariants per `docs/contracts/vox/VoxIntentPacket.v1.md`:
 *   - dictation     ⇒ compiled_prompt MUST be null
 *   - prompt_polish ⇒ compiled_prompt MUST NOT be null and the template id present
 *   - intent_compile⇒ compiled_prompt MUST NOT be null and the template id present
 *   - executor_hint stays `none` in V0/V1; in V2 it MAY be a non-executing
 *     hint (`terminal_propose`, `edit`, `note`) — the Kernel itself never
 *     executes, but the hint informs V3 confirmation policies.
 *   - human_input_text is the operator's transcript verbatim across modes.
 */
final class VoxCompiler
{
    public function __construct(
        private readonly VoxRiskClassifier $riskClassifier,
        private readonly VoxPromptPolisher $polisher,
        private readonly VoxIntentExtractor $extractor,
        private readonly VoxPromptCompiler $promptCompiler,
    ) {}

    /**
     * @param  array<string,mixed>  $transcript  Validated VoxTranscript payload.
     * @param  array<string,mixed>  $hints       Optional caller hints (provider_hint,
     *                                           output_format, context_refs) used by
     *                                           V2 intent_compile.
     * @return array<string,mixed> VoxIntentPacket.v1
     */
    public function compile(array $transcript, string $mode, array $hints = []): array
    {
        $humanInput = (string) ($transcript['text'] ?? '');
        $sessionId = (string) $transcript['session_id'];
        $transcriptId = (string) $transcript['transcript_id'];

        $base = [
            'schema' => VoxSchema::INTENT_PACKET,
            'session_id' => $sessionId,
            'intent_id' => (string) Str::uuid(),
            'transcript_ref' => $transcriptId,
            'mode' => $mode,
            'context_refs' => [
                ['kind' => 'none', 'ref' => null, 'resolved' => true],
            ],
            'executor_hint' => 'none',
            'output_format' => 'text',
            'human_input_text' => $humanInput,
            'discordance_hint' => null,
            'memory_candidate' => null,
            'compiler_version' => VoxSchema::COMPILER_VERSION,
        ];

        if ($mode === VoxSchema::MODE_DICTATION) {
            $risk = $this->riskClassifier->classify($transcript, $mode);

            return array_merge($base, [
                'risk_class' => $risk['risk_class'],
                'risk_reasoning' => $risk['risk_reasoning'],
                'goal' => '',
                'constraints' => [],
                'provider_hint' => 'local',
                'compiled_prompt' => null,
                'compiled_prompt_template' => null,
            ]);
        }

        if ($mode === VoxSchema::MODE_PROMPT_POLISH) {
            $polish = $this->polisher->polish($humanInput);
            $risk = $this->riskClassifier->classify($transcript, $mode);

            return array_merge($base, [
                'risk_class' => $risk['risk_class'],
                'risk_reasoning' => $risk['risk_reasoning'],
                'goal' => $polish['goal'],
                'constraints' => $polish['constraints'],
                'provider_hint' => $polish['provider_hint'],
                'compiled_prompt' => $polish['compiled_prompt'],
                'compiled_prompt_template' => $polish['compiled_prompt_template'],
                'compiler_telemetry' => [
                    'transformations_applied' => $polish['transformations_applied'],
                ],
            ]);
        }

        if ($mode === VoxSchema::MODE_INTENT_COMPILE
            || $mode === VoxSchema::MODE_GOVERNED_EXECUTE
        ) {
            $extracted = $this->extractor->extract($transcript, $hints);
            $risk = $this->riskClassifier->classify($transcript, $mode, [
                'risk_class' => $extracted['risk_class'],
                'risk_reasoning' => $extracted['risk_reasoning'],
            ]);

            $compiled = $this->promptCompiler->compile($humanInput, [
                'goal' => $extracted['goal'],
                'constraints' => $extracted['constraints'],
                'provider_hint' => $extracted['provider_hint'],
                'executor_hint' => $extracted['executor_hint'],
                'output_format' => $extracted['output_format'],
                'context_refs' => $extracted['context_refs'],
                'risk_class' => $risk['risk_class'],
                'risk_markers' => $extracted['risk_markers'],
                'normalised_text' => $extracted['normalised_text'],
            ]);

            return array_merge($base, [
                'risk_class' => $risk['risk_class'],
                'risk_reasoning' => $risk['risk_reasoning'],
                'goal' => $extracted['goal'],
                'constraints' => $extracted['constraints'],
                'provider_hint' => $extracted['provider_hint'],
                'executor_hint' => $extracted['executor_hint'],
                'output_format' => $extracted['output_format'],
                'context_refs' => $extracted['context_refs'],
                'compiled_prompt' => $compiled['compiled_prompt'],
                'compiled_prompt_template' => $compiled['compiled_prompt_template'],
                'compiler_telemetry' => [
                    'provider_hint_source' => $extracted['provider_hint_source'],
                    'output_format_source' => $extracted['output_format_source'],
                    'risk_markers' => $extracted['risk_markers'],
                    'polisher_transformations' => $extracted['polisher_transformations'],
                    'prompt_sections' => $compiled['sections'],
                    // V6-FPG-B · score + diagnóstico de qualidade do prompt.
                    // Determinístico, sem chamada externa. Cert V6 amarra
                    // score mínimo nas 4 vozes canônicas.
                    'quality_self_check' => $compiled['quality_self_check'] ?? null,
                ],
            ]);
        }

        throw new \LogicException(
            "VoxCompiler supports dictation, prompt_polish, intent_compile and governed_execute; got: {$mode}"
        );
    }
}
