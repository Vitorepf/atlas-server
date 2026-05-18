<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxCompiler;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxReceiptService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Atlas Vox V0 Kernel surface.
 *
 * Scope (Onda 2 / Claude C): dictation only.
 *   - GET  /ai/vox/health     — capability advertisement
 *   - POST /ai/vox/intent     — VoxTranscript -> VoxIntentPacket + R0 receipt
 *   - POST /ai/vox/execute    — record action outcome; return desktop_action
 *
 * The Kernel here does NOT touch the clipboard, NOT call any provider,
 * NOT execute any terminal command. It authorises, records, and hands the
 * text back to the Atlas Desktop. Lei 0.75 enforced by the receipt flags.
 */
final class AtlasAiVoxController extends Controller
{
    public function __construct(
        private readonly VoxCompiler $compiler,
        private readonly VoxReceiptService $receiptService,
        private readonly VoxActionOutcomeService $outcomeService,
        private readonly VoxEvidenceService $evidence,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'schema' => VoxSchema::HEALTH,
            'status' => 'available',
            'version' => VoxSchema::KERNEL_VOX_VERSION,
            'compiler_version' => VoxSchema::COMPILER_VERSION,
            'mode' => 'dictation_only',
            'laws' => ['0', '0.5', '0.75', '0.9'],
            'voice_realtime_status' => 'paused_until_v6',
            'supports' => [
                'dictation' => true,
                'prompt_polish' => false,
                'intent_compile' => false,
                'governed_execute' => false,
            ],
            'kernel_guarantees' => [
                'provider_call' => false,
                'tool_call' => false,
                'raw_audio_accepted' => false,
                'external_side_effect' => false,
                'clipboard_handled_by' => 'atlas_desktop',
            ],
        ]);
    }

    public function intent(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'schema' => ['nullable', 'string', 'in:'.VoxSchema::TRANSCRIPT],
            'session_id' => ['required', 'string', 'max:120'],
            'transcript_id' => ['required', 'string', 'max:120'],
            'audio_handle' => ['nullable', 'string', 'max:120'],
            'language' => ['required', 'string', 'in:'.VoxSchema::DEFAULT_LANGUAGE],
            'engine' => ['nullable', 'string', 'max:120'],
            'engine_invocation_id' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:12000'],
            'text_raw' => ['nullable', 'string', 'max:12000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'words' => ['nullable', 'array'],
            'personal_dictionary_applied' => ['nullable', 'array'],
            'personal_dictionary_applied.*' => ['string', 'max:240'],
            'post_corrections' => ['nullable', 'array'],
            'latency_ms' => ['nullable', 'array'],
            'noise_signals' => ['nullable', 'array'],
            'raw_pcm_persisted' => ['required', 'boolean'],
            'eclipse_check' => ['nullable', 'string', 'in:passed,aborted_mid_capture'],
            'mode_requested' => ['required', 'string', 'in:'.VoxSchema::MODE_DICTATION],
        ]);

        if ($payload['raw_pcm_persisted'] === true) {
            $event = $this->evidence->actionBlocked(
                reasonCode: 'raw_pcm_persisted_forbidden',
                message: 'raw_pcm_persisted=true is rejected by Kernel Vox V0',
                payload: [
                    'session_id' => $payload['session_id'],
                    'transcript_id' => $payload['transcript_id'],
                ],
            );
            throw ValidationException::withMessages([
                'raw_pcm_persisted' => 'raw_pcm_persisted must be false in V0 (event: '.$event['event_kind'].')',
            ]);
        }

        if (($payload['eclipse_check'] ?? 'passed') === 'aborted_mid_capture') {
            $this->evidence->actionBlocked(
                reasonCode: 'eclipse_aborted_mid_capture',
                message: 'transcript was aborted mid-capture; Kernel discards',
                payload: [
                    'session_id' => $payload['session_id'],
                    'transcript_id' => $payload['transcript_id'],
                ],
            );
            throw ValidationException::withMessages([
                'eclipse_check' => 'eclipse aborted mid-capture; transcript discarded',
            ]);
        }

        $transcriptReadyEvent = $this->evidence->transcriptReady($payload);

        $intentPacket = $this->compiler->compile($payload, $payload['mode_requested']);
        $intentCompiledEvent = $this->evidence->intentCompiled($intentPacket);

        $receipt = $this->receiptService->issueR0($intentPacket);
        $policyEvent = $this->evidence->policyEvaluated($intentPacket, $receipt);

        // Cache intent+receipt so /execute can validate the (intent_id, receipt_id)
        // pair without a database write. Short TTL — V0 dictation is meant to
        // resolve in seconds; if the operator stalls, they re-dictate.
        $this->stash($intentPacket, $receipt, $payload['text']);

        return response()->json([
            'schema' => VoxSchema::INTENT_RESPONSE,
            'intent_packet' => $intentPacket,
            'receipt' => $receipt,
            'preview' => [
                'what_i_heard' => $payload['text'],
                'what_i_understood' => 'Ditado local: texto pronto para inserir/copiar.',
                'what_i_will_do' => 'Retornar texto ao Atlas Desktop para clipboard/campo focado.',
                'risk_class' => $intentPacket['risk_class'],
                'evidence_promise' => 'Registrar VOX_TRANSCRIPT_READY, VOX_INTENT_COMPILED, VOX_POLICY_EVALUATED e receipt R0.',
            ],
            'confirmation_required' => false,
            'actions_available' => [
                VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD,
                VoxSchema::DESKTOP_ACTION_INSERT_TEXT,
                VoxSchema::DESKTOP_ACTION_CANCEL,
            ],
            'events' => [
                $transcriptReadyEvent,
                $intentCompiledEvent,
                $policyEvent,
            ],
        ]);
    }

    public function execute(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'intent_id' => ['required', 'string', 'max:120'],
            'receipt_id' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'string', 'in:'
                .VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD.','
                .VoxSchema::DESKTOP_ACTION_INSERT_TEXT.','
                .VoxSchema::DESKTOP_ACTION_CANCEL,
            ],
        ]);

        $stashed = $this->fetch($payload['intent_id'], $payload['receipt_id']);
        if ($stashed === null) {
            $this->evidence->actionBlocked(
                reasonCode: 'intent_receipt_unknown_or_expired',
                message: 'No active Vox intent matched (intent_id, receipt_id).',
                payload: [
                    'intent_id' => $payload['intent_id'],
                    'receipt_id' => $payload['receipt_id'],
                ],
            );
            throw ValidationException::withMessages([
                'intent_id' => 'unknown or expired intent/receipt pair',
            ]);
        }

        $intentPacket = $stashed['intent_packet'];
        $receipt = $stashed['receipt'];
        $clipboardText = $stashed['text'];

        if ($payload['decision'] === VoxSchema::DESKTOP_ACTION_CANCEL) {
            $outcome = $this->outcomeService->cancelled($intentPacket, $receipt);
            $event = $this->evidence->evidenceRecorded($outcome);
            $this->forget($payload['intent_id'], $payload['receipt_id']);

            return response()->json([
                'schema' => VoxSchema::EXECUTE_RESPONSE,
                'status' => 'cancelled',
                'action_outcome' => $outcome,
                'desktop_action' => null,
                'events' => [$event],
            ]);
        }

        $executor = $payload['decision'] === VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD
            ? VoxSchema::EXECUTOR_CLIPBOARD_WRITE
            : VoxSchema::EXECUTOR_CLIPBOARD_WRITE;

        $outcome = $this->outcomeService->completed(
            intentPacket: $intentPacket,
            receipt: $receipt,
            executor: $executor,
            clipboardText: $clipboardText,
        );
        $event = $this->evidence->evidenceRecorded($outcome);
        $this->forget($payload['intent_id'], $payload['receipt_id']);

        return response()->json([
            'schema' => VoxSchema::EXECUTE_RESPONSE,
            'status' => 'completed',
            'action_outcome' => $outcome,
            'desktop_action' => [
                'kind' => $payload['decision'],
                'text' => $clipboardText,
            ],
            'events' => [$event],
        ]);
    }

    private function rejectAudioFields(Request $request): void
    {
        $forbidden = VoxSchema::prohibitedAudioFields();
        $all = $request->all();
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $all)) {
                $this->evidence->actionBlocked(
                    reasonCode: 'raw_audio_field_forbidden',
                    message: "Field {$field} is forbidden in Vox Kernel V0",
                    payload: [
                        'forbidden_field' => $field,
                    ],
                );
                throw ValidationException::withMessages([
                    $field => "Field '{$field}' is forbidden — raw audio must never reach the Kernel (Lei 0.75)",
                ]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     */
    private function stash(array $intentPacket, array $receipt, string $text): void
    {
        $key = $this->stashKey((string) $intentPacket['intent_id'], (string) $receipt['receipt_id']);
        Cache::put($key, [
            'intent_packet' => $intentPacket,
            'receipt' => $receipt,
            'text' => $text,
        ], now()->addMinutes(5));
    }

    /**
     * @return array{intent_packet: array<string,mixed>, receipt: array<string,mixed>, text: string}|null
     */
    private function fetch(string $intentId, string $receiptId): ?array
    {
        $key = $this->stashKey($intentId, $receiptId);
        $value = Cache::get($key);
        if (! is_array($value)) {
            return null;
        }
        if (! isset($value['intent_packet'], $value['receipt'], $value['text'])) {
            return null;
        }

        return $value;
    }

    private function forget(string $intentId, string $receiptId): void
    {
        Cache::forget($this->stashKey($intentId, $receiptId));
    }

    private function stashKey(string $intentId, string $receiptId): string
    {
        return 'vox:v0:intent:'.$intentId.':'.$receiptId;
    }
}
