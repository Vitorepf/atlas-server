<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Carbon;

/**
 * Validates every governed-execute request before it reaches an executor.
 *
 * Order of checks (fail-closed, first-failure-wins):
 *   1. Raw audio fields are not present in the request payload.
 *   2. The intent_id ↔ receipt_id pair maps to a known stash.
 *   3. The receipt hasn't expired (best-effort: receipt carries `expires_at`).
 *   4. The intent_packet.mode is `governed_execute` (V3-only gate).
 *   5. The chosen executor is in the allow list for the operator's risk class.
 *   6. The compiled_prompt / proposed text passes the hard-veto regex list.
 *   7. The confirmation token validates and consumes (single-use).
 *   8. Literal confirmation text matches for R4.
 *
 * On block: emits VOX_ACTION_BLOCKED with a structured reason_code and
 * returns a decision so the controller can return a deterministic 422/403
 * without ever calling the executor.
 */
final class VoxExecutionGate
{
    public function __construct(
        private readonly VoxConfirmationService $confirmation,
        private readonly VoxEvidenceService $evidence,
    ) {}

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array{
     *     request_id: string,
     *     decision: string,
     *     confirmation_token: ?string,
     *     literal_confirmation_input: ?string,
     *     executor: string,
     *     prompt_text: string,
     *     raw_payload: array<string,mixed>,
     * } $request
     * @return array{
     *     allowed: bool,
     *     reason_code?: string,
     *     message?: string,
     *     event?: array<string,mixed>,
     * }
     */
    public function evaluate(array $intentPacket, array $receipt, array $request): array
    {
        // 1. Raw audio fields anywhere in the inbound payload.
        $audioField = $this->detectAudioField($request['raw_payload']);
        if ($audioField !== null) {
            return $this->block(
                code: 'raw_audio_field_forbidden',
                message: "Field '{$audioField}' is forbidden — raw audio must never reach the Kernel (Lei 0.75)",
                intentPacket: $intentPacket,
                receipt: $receipt,
                extra: ['forbidden_field' => $audioField],
            );
        }

        // 2. Mode must be governed_execute.
        $mode = (string) ($intentPacket['mode'] ?? '');
        if ($mode !== VoxSchema::MODE_GOVERNED_EXECUTE) {
            return $this->block(
                code: 'gate_only_governed_execute',
                message: "VoxExecutionGate accepts only mode=governed_execute; got '{$mode}'",
                intentPacket: $intentPacket,
                receipt: $receipt,
            );
        }

        // 3. Receipt expiry.
        $expires = (string) ($receipt['expires_at'] ?? '');
        if ($expires !== '' && Carbon::parse($expires)->isPast()) {
            return $this->block(
                code: 'receipt_expired',
                message: 'Receipt has expired; re-compile intent before executing.',
                intentPacket: $intentPacket,
                receipt: $receipt,
            );
        }

        // 4. Hard veto on the compiled_prompt + human_input combined surface.
        $textSurface = trim(
            (string) ($request['prompt_text'] ?? '')
            .' '.(string) ($intentPacket['compiled_prompt'] ?? '')
            .' '.(string) ($intentPacket['human_input_text'] ?? '')
        );
        $vetoLabel = VoxHardVetoList::firstViolation($textSurface);
        if ($vetoLabel !== null) {
            return $this->block(
                code: 'hard_veto_'.$vetoLabel,
                message: "Hard veto matched: '{$vetoLabel}'. Execution refused — operator must rewrite the intent.",
                intentPacket: $intentPacket,
                receipt: $receipt,
                extra: ['veto_label' => $vetoLabel],
            );
        }

        // 5. Executor allow-list by risk class.
        $executor = $request['executor'];
        $risk = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);
        if (! $this->executorAllowedForRisk($executor, $risk)) {
            return $this->block(
                code: 'executor_not_allowed_for_risk_class',
                message: "Executor '{$executor}' is not allowed for risk_class '{$risk}'",
                intentPacket: $intentPacket,
                receipt: $receipt,
                extra: ['executor' => $executor, 'risk_class' => $risk],
            );
        }

        // 6. Confirmation token (R0/R1 still require one in governed_execute —
        // we always issued a confirmation request, so we always validate).
        $token = (string) ($request['confirmation_token'] ?? '');
        $consumption = $this->confirmation->consume(
            requestId: $request['request_id'],
            intentId: (string) ($intentPacket['intent_id'] ?? ''),
            receiptId: (string) ($receipt['receipt_id'] ?? ''),
            decision: $request['decision'],
            confirmationToken: $token,
            literalConfirmationInput: $request['literal_confirmation_input'] ?? null,
        );
        if (! $consumption['ok']) {
            return $this->block(
                code: $consumption['code'] ?? 'confirmation_invalid',
                message: $consumption['reason'] ?? 'confirmation invalid',
                intentPacket: $intentPacket,
                receipt: $receipt,
            );
        }

        return ['allowed' => true];
    }

    /**
     * Allow-list of executors per risk class. R3/R4 deliberately exclude
     * `codex_cli`/`claude_cli` because we never want voice to remote-control
     * a provider that could touch the filesystem (the CLI may; we don't
     * mediate that here yet). They CAN ride `terminal_propose` (no execution)
     * and `note_capture` (no side effect on code).
     */
    private function executorAllowedForRisk(string $executor, string $risk): bool
    {
        $matrix = [
            VoxSchema::RISK_R0 => [
                VoxSchema::EXECUTOR_NOTE_CAPTURE,
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
                VoxSchema::EXECUTOR_CODEX_CLI,
                VoxSchema::EXECUTOR_CLAUDE_CLI,
                VoxSchema::EXECUTOR_FILESYSTEM_EDIT,
            ],
            VoxSchema::RISK_R1 => [
                VoxSchema::EXECUTOR_NOTE_CAPTURE,
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
                VoxSchema::EXECUTOR_CODEX_CLI,
                VoxSchema::EXECUTOR_CLAUDE_CLI,
                VoxSchema::EXECUTOR_FILESYSTEM_EDIT,
            ],
            VoxSchema::RISK_R2 => [
                VoxSchema::EXECUTOR_NOTE_CAPTURE,
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
                VoxSchema::EXECUTOR_CODEX_CLI,
                VoxSchema::EXECUTOR_CLAUDE_CLI,
                VoxSchema::EXECUTOR_FILESYSTEM_EDIT,
            ],
            VoxSchema::RISK_R3 => [
                VoxSchema::EXECUTOR_NOTE_CAPTURE,
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
            ],
            VoxSchema::RISK_R4 => [
                VoxSchema::EXECUTOR_NOTE_CAPTURE,
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
            ],
        ];

        return in_array($executor, $matrix[$risk] ?? [], true);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function detectAudioField(array $payload): ?string
    {
        foreach (VoxSchema::prohibitedAudioFields() as $field) {
            if (array_key_exists($field, $payload)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $extra
     * @return array{allowed: false, reason_code: string, message: string, event: array<string,mixed>}
     */
    private function block(
        string $code,
        string $message,
        array $intentPacket,
        array $receipt,
        array $extra = [],
    ): array {
        $event = $this->evidence->actionBlocked(
            reasonCode: $code,
            message: $message,
            payload: array_merge([
                'session_id' => (string) ($intentPacket['session_id'] ?? ''),
                'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
                'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
                'risk_class' => (string) ($intentPacket['risk_class'] ?? ''),
                'executor_hint' => (string) ($intentPacket['executor_hint'] ?? ''),
            ], $extra),
        );

        return [
            'allowed' => false,
            'reason_code' => $code,
            'message' => $message,
            'event' => $event,
        ];
    }
}
