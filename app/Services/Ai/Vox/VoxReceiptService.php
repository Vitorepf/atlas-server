<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues Vox-local receipts for V0/V1/V2.
 *
 * Why a Vox-local receipt service instead of the global
 * DecisionReceiptIssuer: the global issuer pulls in the full
 * OperationEnvelope / AtlasDecide pipeline (provider routing, domain
 * profile, policy compilation) which is out of scope for V0-V2 where the
 * Kernel never calls a provider, never touches a tool and never executes
 * anything. Wedging that pipeline open just to record "Vitor said
 * something and we handed back text" would be a refactor far larger than
 * the feature.
 *
 * Receipts emitted by this service are scoped as
 * `atlas.vox.receipt.r0.v1` (R0) or `atlas.vox.receipt.advisory.v1`
 * (R1-R4 advisory). Honest naming is canon (Lei 0.75).
 *
 * Hard invariants encoded here for V0-V2 (verified by tests):
 *   - provider_call_allowed = false
 *   - tool_call_allowed = false
 *   - raw_audio_allowed = false
 *   - external_side_effect_allowed = false
 *   - issuer = "vox_kernel_v2"
 *
 * V2 receipts for R3/R4 may still be issued because the Kernel records
 * the operator's stated intent; they carry `executable_now = false` and
 * `confirmation_required = true` so V3 can pick up where V2 stopped.
 */
final class VoxReceiptService
{
    public const RECEIPT_TYPE_R0_NO_OP = 'vox_r0_no_op_pass_through';
    public const RECEIPT_TYPE_ADVISORY = 'vox_v2_intent_compile_advisory';
    public const RECEIPT_SCHEMA_ADVISORY = 'atlas.vox.receipt.advisory.v1';

    /**
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    public function issueR0(array $intentPacket): array
    {
        $now = Carbon::now('UTC');
        $mode = (string) ($intentPacket['mode'] ?? VoxSchema::MODE_DICTATION);
        $riskClass = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);

        if ($riskClass !== VoxSchema::RISK_R0) {
            return $this->issueAdvisory($intentPacket, $now);
        }

        return [
            'schema' => VoxSchema::RECEIPT_R0,
            'receipt_id' => 'rcpt_vox_'.Str::uuid()->toString(),
            'receipt_type' => self::RECEIPT_TYPE_R0_NO_OP,
            'risk_class' => VoxSchema::RISK_R0,
            'mode' => $mode,
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'action_authorized' => 'return_text_to_desktop',
            'provider_call_allowed' => false,
            'tool_call_allowed' => false,
            'raw_audio_allowed' => false,
            'external_side_effect_allowed' => false,
            'executable_now' => true,
            'confirmation_required' => false,
            'expires_at' => $now->copy()->addMinutes(5)->toIso8601String(),
            'issued_at' => $now->toIso8601String(),
            'issuer' => 'vox_kernel_v2',
            'issuer_version' => VoxSchema::KERNEL_VOX_VERSION,
            'audit_notes' => [
                'Risco R0: texto/prompt devolvido ao Atlas Desktop; Kernel não chama provider.',
                'Audio cru não entra no Kernel. raw_pcm_persisted deve ser false no transcript.',
            ],
        ];
    }

    /**
     * Advisory receipt for V2 intent_compile when the extractor flags
     * risk > R0. The Kernel still does NOT execute anything; the receipt
     * documents the operator's intent and forces V3 confirmation later.
     *
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    private function issueAdvisory(array $intentPacket, Carbon $now): array
    {
        $riskClass = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);
        $reasoning = (string) ($intentPacket['risk_reasoning'] ?? '');
        $confirmationRequired = in_array(
            $riskClass,
            [VoxSchema::RISK_R2, VoxSchema::RISK_R3, VoxSchema::RISK_R4],
            true,
        );

        $auditNotes = [
            'Risco '.$riskClass.': Kernel V2 apenas compila intenção/prompt — NÃO executa nesta onda.',
            'Texto/prompt devolvido ao Atlas Desktop para o operador escolher copiar/inserir/cancelar.',
            'Audio cru não entra no Kernel; raw_pcm_persisted deve ser false no transcript.',
        ];
        if ($riskClass === VoxSchema::RISK_R4) {
            $auditNotes[] = 'R4 = destrutivo/irreversível. Execução real exigirá V3 com dupla confirmação humana.';
        } elseif ($riskClass === VoxSchema::RISK_R3) {
            $auditNotes[] = 'R3 = execução externa contida. Execução real exigirá V3 com preview de comando.';
        } elseif ($riskClass === VoxSchema::RISK_R2) {
            $auditNotes[] = 'R2 = edição local reversível. Execução real exigirá V3 com confirmação humana.';
        } elseif ($riskClass === VoxSchema::RISK_R1) {
            $auditNotes[] = 'R1 = leitura/análise. Não exige confirmação obrigatória, mas Kernel não executa em V2.';
        }
        if ($reasoning !== '') {
            $auditNotes[] = 'Justificativa do classifier: '.$reasoning;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA_ADVISORY,
            'receipt_id' => 'rcpt_vox_'.Str::uuid()->toString(),
            'receipt_type' => self::RECEIPT_TYPE_ADVISORY,
            'risk_class' => $riskClass,
            'mode' => (string) ($intentPacket['mode'] ?? VoxSchema::MODE_INTENT_COMPILE),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'action_authorized' => 'return_compiled_prompt_to_desktop',
            'provider_call_allowed' => false,
            'tool_call_allowed' => false,
            'raw_audio_allowed' => false,
            'external_side_effect_allowed' => false,
            'executable_now' => false,
            'confirmation_required' => $confirmationRequired,
            'future_governance' => [
                'wave' => 'V3_governed_executor',
                'will_require_human_confirmation' => $confirmationRequired,
                'will_require_double_confirmation' => $riskClass === VoxSchema::RISK_R4,
            ],
            'expires_at' => $now->copy()->addMinutes(5)->toIso8601String(),
            'issued_at' => $now->toIso8601String(),
            'issuer' => 'vox_kernel_v2',
            'issuer_version' => VoxSchema::KERNEL_VOX_VERSION,
            'audit_notes' => $auditNotes,
        ];
    }
}
