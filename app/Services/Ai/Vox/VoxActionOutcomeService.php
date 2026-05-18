<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds VoxActionOutcome.v1 payloads for V0 dictation.
 *
 * V0 executors:
 *   - `no_op_dictation` — Vitor cancelled. Status `aborted`.
 *   - `clipboard_write` — Desktop will copy/insert the text locally.
 *     Status `completed`. Kernel does NOT touch the clipboard itself
 *     (that lives on the Desktop). Kernel emits the outcome so the
 *     ledger has primary evidence.
 *
 * Raw audio is forbidden everywhere here — there are no `audio_*`
 * artifact kinds because audio bytes never reach this service.
 */
final class VoxActionOutcomeService
{
    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function completed(
        array $intentPacket,
        array $receipt,
        string $executor,
        string $clipboardText,
        string $sourceText = VoxSchema::SOURCE_TEXT_ORIGINAL,
        ?string $desktopActionKind = null,
    ): array {
        $started = Carbon::now('UTC');
        $completed = $started->copy(); // synchronous V0/V1 — duration is essentially zero
        $payloadSize = strlen($clipboardText);

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => $executor,
            'executor_version' => 'vox-kernel-v0@'.VoxSchema::KERNEL_VOX_VERSION,
            'status' => 'completed',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => 0,
            'artifacts' => [
                [
                    'kind' => 'clipboard_payload',
                    'ref' => 'art_clip_'.Str::uuid()->toString(),
                    'size_bytes' => $payloadSize,
                    'sha256' => hash('sha256', $clipboardText),
                ],
            ],
            'error' => null,
            'regret_signals' => [],
            'follow_up_required' => false,
            'follow_up_kind' => null,
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => 0,
                'network_bytes' => 0,
            ],
            'executor_violations' => [],
            'metadata' => [
                'executed_by' => 'atlas_desktop',
                'kernel_role' => 'authorize_and_record_only',
                'source_text' => $sourceText,
                'desktop_action_kind' => $desktopActionKind,
                'intent_mode' => (string) ($intentPacket['mode'] ?? ''),
            ],
        ];
    }

    /**
     * V3 terminal_propose · NEVER executes. Records the proposed command as
     * an artifact and marks `metadata.command_executed=false` for audit.
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $metadataExtras
     * @return array<string,mixed>
     */
    public function terminalProposed(
        array $intentPacket,
        array $receipt,
        string $proposedCommand,
        string $explanation,
        array $metadataExtras = [],
    ): array {
        $started = Carbon::now('UTC');
        $completed = $started->copy();
        $size = strlen($proposedCommand);

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
            'executor_version' => 'vox-terminal-propose@'.VoxSchema::KERNEL_VOX_VERSION,
            'status' => 'completed',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => 0,
            'artifacts' => [
                [
                    'kind' => 'command_string',
                    'ref' => 'art_cmd_'.Str::uuid()->toString(),
                    'size_bytes' => $size,
                    'sha256' => hash('sha256', $proposedCommand),
                ],
            ],
            'error' => null,
            'regret_signals' => [],
            'follow_up_required' => true,
            'follow_up_kind' => 'manual_terminal_run',
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => 0,
                'network_bytes' => 0,
            ],
            'executor_violations' => [],
            'metadata' => array_merge([
                'executed_by' => 'atlas_desktop_operator',
                'kernel_role' => 'propose_only_never_execute',
                'command_proposed' => $proposedCommand,
                'command_executed' => false,
                'explanation' => $explanation,
                'intent_mode' => (string) ($intentPacket['mode'] ?? ''),
            ], $metadataExtras),
        ];
    }

    /**
     * V3 note_capture · creates an "Atlas Inbox candidate" handle. When no
     * inbox backend is wired the Kernel returns the outcome with status
     * `escalated` and a desktop_action so the operator can save the note
     * client-side honestly, instead of pretending success.
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function noteCaptured(
        array $intentPacket,
        array $receipt,
        string $noteText,
        ?string $noteRef,
    ): array {
        $started = Carbon::now('UTC');
        $completed = $started->copy();
        $hasInbox = $noteRef !== null && $noteRef !== '';
        $size = strlen($noteText);

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => VoxSchema::EXECUTOR_NOTE_CAPTURE,
            'executor_version' => 'vox-note-capture@'.VoxSchema::KERNEL_VOX_VERSION,
            'status' => $hasInbox ? 'completed' : 'escalated',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => 0,
            'artifacts' => $hasInbox ? [[
                'kind' => 'note_id',
                'ref' => $noteRef,
                'size_bytes' => $size,
                'sha256' => hash('sha256', $noteText),
            ]] : [],
            'error' => null,
            'regret_signals' => [],
            'follow_up_required' => ! $hasInbox,
            'follow_up_kind' => $hasInbox ? null : 'escalate_human',
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => 0,
                'network_bytes' => 0,
            ],
            'executor_violations' => [],
            'metadata' => [
                'executed_by' => $hasInbox ? 'atlas_kernel' : 'atlas_desktop_operator',
                'kernel_role' => $hasInbox ? 'persist_note' : 'delegate_to_desktop_save_as_note',
                'inbox_available' => $hasInbox,
                'intent_mode' => (string) ($intentPacket['mode'] ?? ''),
            ],
        ];
    }

    /**
     * V3 provider CLI shell-out · success path.
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function providerCliCompleted(
        array $intentPacket,
        array $receipt,
        string $executor,
        string $executorVersion,
        string $stdout,
        string $stderr,
        int $exitCode,
        int $durationMs,
        string $workingDirectory,
    ): array {
        $started = Carbon::now('UTC')->subMillis($durationMs);
        $completed = Carbon::now('UTC');

        $artifacts = [];
        if ($stdout !== '') {
            $artifacts[] = [
                'kind' => 'stdout',
                'ref' => 'art_stdout_'.Str::uuid()->toString(),
                'size_bytes' => strlen($stdout),
                'sha256' => hash('sha256', $stdout),
            ];
        }
        if ($stderr !== '') {
            $artifacts[] = [
                'kind' => 'stderr',
                'ref' => 'art_stderr_'.Str::uuid()->toString(),
                'size_bytes' => strlen($stderr),
                'sha256' => hash('sha256', $stderr),
            ];
        }

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => $executor,
            'executor_version' => $executorVersion,
            'status' => 'completed',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => $durationMs,
            'artifacts' => $artifacts,
            'error' => null,
            'regret_signals' => [],
            'follow_up_required' => true,
            'follow_up_kind' => 'review_diff',
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => $durationMs,
                'network_bytes' => 0,
            ],
            'executor_violations' => [],
            'metadata' => [
                'executed_by' => 'atlas_kernel_vox_v3',
                'working_directory' => $workingDirectory,
                'exit_code' => $exitCode,
                'intent_mode' => (string) ($intentPacket['mode'] ?? ''),
            ],
        ];
    }

    /**
     * V3 generic blocked path · executor refused to act (config missing,
     * filesystem_edit infra absent, etc). status=aborted + executor_violations
     * populated so the ledger can surface a VOX_ACTION_BLOCKED automatically.
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $metadataExtras
     * @return array<string,mixed>
     */
    public function executorBlocked(
        array $intentPacket,
        array $receipt,
        string $executor,
        string $reasonCode,
        string $message,
        array $metadataExtras = [],
    ): array {
        $started = Carbon::now('UTC');
        $completed = $started->copy();

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => $executor,
            'executor_version' => 'vox-executor-blocked@'.VoxSchema::KERNEL_VOX_VERSION,
            'status' => 'aborted',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => 0,
            'artifacts' => [],
            'error' => [
                'kind' => $reasonCode,
                'message' => $message,
                'exit_code' => null,
            ],
            'regret_signals' => [],
            'follow_up_required' => true,
            'follow_up_kind' => 'escalate_human',
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => 0,
                'network_bytes' => 0,
            ],
            'executor_violations' => [$reasonCode],
            'metadata' => array_merge([
                'executed_by' => 'atlas_kernel_vox_v3',
                'kernel_role' => 'block_executor',
                'reason_code' => $reasonCode,
                'intent_mode' => (string) ($intentPacket['mode'] ?? ''),
            ], $metadataExtras),
        ];
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function cancelled(array $intentPacket, array $receipt): array
    {
        $started = Carbon::now('UTC');
        $completed = $started->copy();

        return [
            'schema' => VoxSchema::ACTION_OUTCOME,
            'outcome_id' => (string) Str::uuid(),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => VoxSchema::EXECUTOR_NO_OP_DICTATION,
            'executor_version' => 'vox-kernel-v0@'.VoxSchema::KERNEL_VOX_VERSION,
            'status' => 'aborted',
            'started_at' => $started->toIso8601String(),
            'completed_at' => $completed->toIso8601String(),
            'duration_ms' => 0,
            'artifacts' => [],
            'error' => [
                'kind' => 'operator_cancelled',
                'message' => 'Operator chose cancel in the Atlas Desktop overlay.',
                'exit_code' => null,
            ],
            'regret_signals' => [],
            'follow_up_required' => false,
            'follow_up_kind' => null,
            'cost_signals' => [
                'provider_tokens_in' => null,
                'provider_tokens_out' => null,
                'local_compute_ms' => 0,
                'network_bytes' => 0,
            ],
            'executor_violations' => [],
            'metadata' => [
                'executed_by' => 'atlas_desktop',
                'kernel_role' => 'record_cancellation_only',
            ],
        ];
    }
}
