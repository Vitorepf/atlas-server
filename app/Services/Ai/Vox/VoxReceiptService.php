<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues the Vox R0 no-op pass-through receipt for V0 dictation.
 *
 * Why a Vox-local receipt service instead of the global
 * DecisionReceiptIssuer: the global issuer is wired into the full
 * OperationEnvelope/AtlasDecide pipeline and pulls in provider routing,
 * domain profile and policy compilation that are out of scope for V0
 * dictation. Wedging that pipeline open for a pass-through receipt would
 * be a refactor far larger than the feature.
 *
 * We instead emit a receipt explicitly scoped as
 * `atlas.vox.receipt.r0.v1` so audits can tell at a glance this is a
 * Vox-local R0 receipt, not a full Kernel Decision Receipt. Honest
 * naming is the canon (Lei 0.75).
 *
 * Hard invariants encoded here:
 *   - provider_call_allowed = false
 *   - tool_call_allowed = false
 *   - raw_audio_allowed = false
 *   - external_side_effect_allowed = false
 *   - action_authorized = "return_text_to_desktop"
 *   - issuer = "vox_kernel_v0"
 *
 * These flags are checked in tests and must never flip true under V0.
 */
final class VoxReceiptService
{
    /**
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    public function issueR0(array $intentPacket): array
    {
        $now = Carbon::now('UTC');

        return [
            'schema' => VoxSchema::RECEIPT_R0,
            'receipt_id' => 'rcpt_vox_'.Str::uuid()->toString(),
            'receipt_type' => 'vox_r0_no_op_pass_through',
            'risk_class' => VoxSchema::RISK_R0,
            'mode' => $intentPacket['mode'] ?? VoxSchema::MODE_DICTATION,
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'action_authorized' => 'return_text_to_desktop',
            'provider_call_allowed' => false,
            'tool_call_allowed' => false,
            'raw_audio_allowed' => false,
            'external_side_effect_allowed' => false,
            'confirmation_required' => false,
            'expires_at' => $now->copy()->addMinutes(5)->toIso8601String(),
            'issued_at' => $now->toIso8601String(),
            'issuer' => 'vox_kernel_v0',
            'issuer_version' => VoxSchema::KERNEL_VOX_VERSION,
            'audit_notes' => [
                'V0 dictation: text is handed back to Atlas Desktop; Kernel performs no provider call.',
                'No raw audio enters the Kernel. raw_pcm_persisted must be false in the source transcript.',
            ],
        ];
    }
}
