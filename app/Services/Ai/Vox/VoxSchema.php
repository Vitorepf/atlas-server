<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Canonical schema identifiers and shared constants for the Atlas Vox V0
 * Kernel surface. Aligned with `docs/contracts/vox/*` v1.
 *
 * Anti-drift: every Vox payload that crosses the HTTP boundary embeds the
 * canonical `schema` string from here. Tests assert these values directly,
 * so any rename here forces the test suite to flag the rename.
 */
final class VoxSchema
{
    public const HEALTH = 'atlas.vox.health.v1';
    public const TRANSCRIPT = 'atlas.vox.transcript.v1';
    public const INTENT_PACKET = 'atlas.vox.intent_packet.v1';
    public const INTENT_RESPONSE = 'atlas.vox.intent_response.v1';
    public const ACTION_OUTCOME = 'atlas.vox.action_outcome.v1';
    public const EXECUTE_RESPONSE = 'atlas.vox.execute_response.v1';
    public const RECEIPT_R0 = 'atlas.vox.receipt.r0.v1';

    public const COMPILER_VERSION = '0.1.0';
    public const KERNEL_VOX_VERSION = '0.1.0';

    public const DEFAULT_LANGUAGE = 'pt-BR';

    public const MODE_DICTATION = 'dictation';
    public const MODE_PROMPT_POLISH = 'prompt_polish';
    public const MODE_INTENT_COMPILE = 'intent_compile';
    public const MODE_GOVERNED_EXECUTE = 'governed_execute';

    public const RISK_R0 = 'R0';
    public const RISK_R1 = 'R1';
    public const RISK_R2 = 'R2';
    public const RISK_R3 = 'R3';
    public const RISK_R4 = 'R4';

    public const EXECUTOR_NO_OP_DICTATION = 'no_op_dictation';
    public const EXECUTOR_CLIPBOARD_WRITE = 'clipboard_write';

    public const DESKTOP_ACTION_COPY_TO_CLIPBOARD = 'copy_to_clipboard';
    public const DESKTOP_ACTION_INSERT_TEXT = 'insert_text';
    public const DESKTOP_ACTION_CANCEL = 'cancel';

    /**
     * Field names that MUST NOT appear in any Vox payload reaching the
     * Kernel. Audio bytes are an Edge-only artefact (Mac Edge in-memory)
     * and the Kernel rejects any request that even mentions them.
     *
     * @return list<string>
     */
    public static function prohibitedAudioFields(): array
    {
        return [
            'audio_bytes',
            'raw_audio',
            'audio_base64',
            'audio_data',
            'audio_payload',
            'pcm',
            'raw_pcm',
            'pcm_f32',
            'pcm_bytes',
            'wav',
            'wav_base64',
        ];
    }
}
