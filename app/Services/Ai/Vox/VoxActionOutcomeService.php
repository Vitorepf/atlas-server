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
    ): array {
        $started = Carbon::now('UTC');
        $completed = $started->copy(); // synchronous V0 — duration is essentially zero
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
            ],
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
