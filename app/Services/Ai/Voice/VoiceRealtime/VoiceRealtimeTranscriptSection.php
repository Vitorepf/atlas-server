<?php

namespace App\Services\Ai\Voice\VoiceRealtime;

use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use Throwable;

/**
 * Voice transcript -> AI interaction dispatch, split from {@see AtlasVoiceRealtimeService} (GOD-DEBULK).
 */
final class VoiceRealtimeTranscriptSection
{
    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly VoiceRealtimeSupport $support,
    ) {}

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function maybeEnqueueTranscriptInteraction(
        array $session,
        array $payload,
        string $turnId,
        string $transcript,
        DecisionReceipt $receipt,
    ): array {
        if ($transcript === '') {
            return [
                'dispatched' => false,
                'status' => 'skipped_no_transcript',
                'reason' => 'voice_turn_has_no_transcript_final',
            ];
        }

        if (! (bool) ($payload['dispatch_to_ai'] ?? false)) {
            return [
                'dispatched' => false,
                'status' => 'skipped_not_requested',
                'reason' => 'dispatch_to_ai_not_requested',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        if (! (bool) ($payload['allow_transcript_persistence'] ?? false)) {
            return [
                'dispatched' => false,
                'status' => 'blocked_transcript_persistence_not_allowed',
                'reason' => 'voice_contract_requires_explicit_transcript_persistence_for_ai_interaction',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        if ($this->support->looksLikeSttGhostTranscript($transcript)) {
            return [
                'dispatched' => false,
                'status' => 'skipped_suspect_stt_ghost_transcript',
                'reason' => 'voice_transcript_looked_like_short_url_or_common_whisper_hallucination',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        try {
            $trace = $this->gateway->enqueueInteraction($transcript, [
                'client_id' => 'voice:'.$session['session_id'].':'.$turnId,
                'source_type' => 'voice_realtime',
                'source_id' => $turnId,
                'kind' => 'interaction',
                'thread_id' => is_string($payload['ai_thread_id'] ?? null) ? (string) $payload['ai_thread_id'] : null,
                'new_thread' => ! is_string($payload['ai_thread_id'] ?? null),
                'agent_slug' => config('atlas.ai.default_agent', 'orquestrador'),
                'include_semantic_context' => true,
                'context_note_limit' => 5,
                'payload' => [
                    'app_surface' => 'voice_realtime',
                    'atlas_focus' => $this->support->string($payload['domain_hint'] ?? 'general', 120) ?: 'general',
                    'routing_task' => 'voice_turn',
                    'routing_domain' => $this->support->string($payload['domain_hint'] ?? 'general', 120) ?: 'general',
                    'response_style' => 'conversational',
                    'privacy' => [
                        'source' => 'voice_realtime_transcript',
                        'privacy_class' => $session['privacy_class'],
                        'raw_audio_persisted' => false,
                        'transcript_persistence_explicitly_allowed' => true,
                    ],
                    'voice_realtime' => [
                        'schema_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
                        'session_id' => $session['session_id'],
                        'turn_id' => $turnId,
                        'transport' => $session['transport'],
                        'runtime' => $session['runtime'],
                        'audio_hash' => $this->support->sha256Hex($payload['audio_hash'] ?? null),
                        'audio_duration_ms' => $payload['audio_duration_ms'] ?? null,
                        'transcript_hash' => hash('sha256', $transcript),
                        'decision_receipt_id' => $receipt->receiptId,
                        'decision_receipt_hash' => $receipt->receiptHash,
                        'decision_domain' => $receipt->domain,
                        'decision_flow' => $receipt->flow,
                    ],
                ],
            ]);

            return [
                'dispatched' => true,
                'status' => 'ai_interaction_enqueued',
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'trace_status' => $trace->status,
                'transcript_hash' => hash('sha256', $transcript),
            ];
        } catch (Throwable $error) {
            return [
                'dispatched' => false,
                'status' => 'ai_interaction_enqueue_failed',
                'error_class' => $error::class,
                'error_message_hash' => hash('sha256', $error->getMessage()),
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }
    }
}
