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
    public const READINESS = 'atlas.vox.readiness.v1';
    public const TRANSCRIPT = 'atlas.vox.transcript.v1';
    public const INTENT_PACKET = 'atlas.vox.intent_packet.v1';
    public const INTENT_RESPONSE = 'atlas.vox.intent_response.v1';
    public const ACTION_OUTCOME = 'atlas.vox.action_outcome.v1';
    public const EXECUTE_RESPONSE = 'atlas.vox.execute_response.v1';
    public const RECEIPT_R0 = 'atlas.vox.receipt.r0.v1';
    public const CONFIRMATION_REQUEST = 'atlas.vox.confirmation_request.v1';
    public const AUTO_MODE_DECISION = 'atlas.vox.auto_mode_decision.v1';
    /** V5-A · Symbiotic Interlocutor decision schema. */
    public const INTERLOCUTOR_DECISION = 'atlas.vox.interlocutor_decision.v1';
    /** V6.5 · Flow Orchestrator decision schema. */
    public const FLOW_DECISION = 'atlas.vox.flow_decision.v1';

    public const COMPILER_VERSION = '0.1.0';
    public const KERNEL_VOX_VERSION = '0.2.0';
    public const AUTO_MODE_ROUTER_VERSION = '0.2.0';
    public const INTERLOCUTOR_VERSION = '0.2.0';
    public const FLOW_ORCHESTRATOR_VERSION = '0.1.0';

    // V6.5 · Composite Intent Splitter — políticas de execução possíveis
    // quando a fala mistura duas ou mais intenções. NUNCA executa em
    // cadeia automaticamente; o frontend pode ignorar o bloco sem perder
    // back-compat (callers antigos seguem lendo só `mode`/`destination`).
    public const COMPOSITE_POLICY_PREVIEW_ONLY = 'preview_only';
    public const COMPOSITE_POLICY_STEP_BY_STEP = 'step_by_step_confirmation';
    public const COMPOSITE_POLICY_SINGLE_SAFE_STEP = 'single_safe_step';

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

    // V0/V1/V2 executors (Kernel-resident, side-effect free).
    public const EXECUTOR_NO_OP_DICTATION = 'no_op_dictation';
    public const EXECUTOR_CLIPBOARD_WRITE = 'clipboard_write';

    // V3 governed executors. The Kernel only invokes these after
    // VoxExecutionGate validates receipt + confirmation_token + hard veto.
    public const EXECUTOR_TERMINAL_PROPOSE = 'terminal_propose';
    public const EXECUTOR_NOTE_CAPTURE = 'note_capture';
    public const EXECUTOR_CODEX_CLI = 'codex_cli';
    public const EXECUTOR_CLAUDE_CLI = 'claude_cli';
    public const EXECUTOR_FILESYSTEM_EDIT = 'filesystem_edit';

    public const DESKTOP_ACTION_COPY_TO_CLIPBOARD = 'copy_to_clipboard';
    public const DESKTOP_ACTION_INSERT_TEXT = 'insert_text';
    public const DESKTOP_ACTION_CANCEL = 'cancel';
    // Prompt polish (V1) extra desktop actions. Compiled-prompt variants
    // hand the polished text back; original variants hand the verbatim
    // transcript back. The Desktop owns the actual paste/clipboard call.
    public const DESKTOP_ACTION_COPY_COMPILED_PROMPT = 'copy_compiled_prompt';
    public const DESKTOP_ACTION_INSERT_COMPILED_PROMPT = 'insert_compiled_prompt';
    public const DESKTOP_ACTION_COPY_ORIGINAL = 'copy_original';

    // V3 governed_execute decisions.
    public const DESKTOP_ACTION_EXECUTE = 'execute';
    public const DESKTOP_ACTION_EDIT_INTENT = 'edit_intent';
    public const DESKTOP_ACTION_SAVE_AS_NOTE = 'save_as_note';

    public const SOURCE_TEXT_COMPILED_PROMPT = 'compiled_prompt';
    public const SOURCE_TEXT_ORIGINAL = 'original';

    /** Default TTL for confirmation tokens (seconds). Aligns with
     * VoxConfirmation.v1 contract default. */
    public const CONFIRMATION_DEFAULT_TTL_SECONDS = 120;

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
