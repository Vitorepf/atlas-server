<?php

namespace App\Jobs;

use App\Models\Capture;
use App\Models\TranscriptionJob;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use App\Services\Semantic\ActivationEngine;
use App\Services\Semantic\CaptureSemanticClarifier;
use App\Services\Semantic\CurationProposalService;
use App\Services\WhisperTranscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAudioTranscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly string $transcriptionJobId) {}

    public function handle(
        WhisperTranscriber $transcriber,
        CaptureSemanticClarifier $clarifier,
        CurationProposalService $curation,
        ActivationEngine $activations,
        AtlasVoiceRealtimeService $voice,
    ): void {
        $job = TranscriptionJob::query()
            ->with('capture')
            ->findOrFail($this->transcriptionJobId);

        if ($job->status === 'done') {
            return;
        }

        $job->update([
            'status' => 'processing',
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $capture = $job->capture;
        $capture->update([
            'transcription_status' => 'processing',
            'transcription_error' => null,
        ]);

        $text = $transcriber->transcribe(Storage::disk('atlas')->path($capture->content_file_path));

        $capture->update([
            'content_text' => $text,
            'transcription_status' => 'done',
            'transcription_engine' => config('atlas.transcription.engine'),
            'transcription_error' => null,
        ]);

        $job->update([
            'status' => 'done',
            'finished_at' => now(),
            'error_message' => null,
        ]);

        $this->dispatchVoiceTurnIfRequested($capture->refresh(), $text, $voice);

        try {
            $capture = $clarifier->handleReady($capture->refresh(), 'transcription_done');
            $proposal = $curation->createFromCapture($capture);
            $clarification = $clarifier->resultFor($capture) ?? [];
            $activations->createForContext('capture_created', [
                'source' => 'transcription_done',
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
                'capture_kind' => $capture->kind,
                'domain' => $capture->domain,
                'suggested_type' => $clarification['suggested_type'] ?? null,
                'future_triggers' => $clarification['future_triggers'] ?? [],
                'density_score' => data_get($clarification, 'density.score'),
                'curation_proposal_id' => $proposal?->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function dispatchVoiceTurnIfRequested(Capture $capture, string $text, AtlasVoiceRealtimeService $voice): void
    {
        $voicePayload = data_get($capture->metadata, 'voice_realtime_dispatch');
        if (! is_array($voicePayload) || ! (bool) ($voicePayload['dispatch_to_ai'] ?? false)) {
            return;
        }

        if (! (bool) ($voicePayload['allow_transcript_persistence'] ?? false)) {
            $this->recordVoiceDispatchResult($capture, [
                'status' => 'blocked_transcript_persistence_not_allowed',
                'dispatched' => false,
                'reason' => 'voice_contract_requires_explicit_transcript_persistence_for_ai_interaction',
            ]);

            return;
        }

        $text = trim($text);
        if ($text === '') {
            $this->recordVoiceDispatchResult($capture, [
                'status' => 'skipped_empty_transcript',
                'dispatched' => false,
                'reason' => 'transcription_completed_without_text',
            ]);

            return;
        }

        try {
            $result = $voice->handleTurn([
                'session_id' => $voicePayload['session_id'] ?? 'capture_'.$capture->client_id,
                'envelope_id' => $voicePayload['envelope_id'] ?? null,
                'receipt_id' => $voicePayload['receipt_id'] ?? null,
                'turn_id' => $voicePayload['turn_id'] ?? 'capture_'.$capture->client_id,
                'audio_hash' => $capture->content_sha256,
                'audio_duration_ms' => $capture->content_duration_ms,
                'transcript' => $text,
                'language' => $voicePayload['language'] ?? config('atlas.transcription.language', 'pt-BR'),
                'domain_hint' => $voicePayload['domain_hint'] ?? $capture->domain,
                'flow_hint' => $voicePayload['flow_hint'] ?? 'voice.push_to_talk',
                'dispatch_to_ai' => true,
                'allow_transcript_persistence' => true,
                'ai_thread_id' => $voicePayload['ai_thread_id'] ?? null,
                'client_surface' => $voicePayload['client_surface'] ?? 'mobile',
                'transport' => $voicePayload['transport'] ?? 'mobile_push_to_talk',
                'runtime' => $voicePayload['runtime'] ?? 'livekit_agents_sdk',
                'privacy_class' => $voicePayload['privacy_class'] ?? 'p3_audio',
            ]);

            $aiInteraction = data_get($result, 'turn.ai_interaction');
            $this->recordVoiceDispatchResult($capture, [
                'status' => is_array($aiInteraction) ? ($aiInteraction['status'] ?? null) : ($result['status'] ?? null),
                'dispatched' => is_array($aiInteraction) ? (bool) ($aiInteraction['dispatched'] ?? false) : false,
                'session_id' => $voicePayload['session_id'] ?? 'capture_'.$capture->client_id,
                'turn_id' => $voicePayload['turn_id'] ?? 'capture_'.$capture->client_id,
                'trace_id' => is_array($aiInteraction) ? ($aiInteraction['trace_id'] ?? null) : null,
                'thread_id' => is_array($aiInteraction) ? ($aiInteraction['thread_id'] ?? null) : null,
                'trace_status' => is_array($aiInteraction) ? ($aiInteraction['trace_status'] ?? null) : null,
                'error_class' => is_array($aiInteraction) ? ($aiInteraction['error_class'] ?? null) : null,
                'error_message_hash' => is_array($aiInteraction) && is_string($aiInteraction['error_message_hash'] ?? null)
                    ? $aiInteraction['error_message_hash']
                    : null,
                'transcript_hash' => is_array($aiInteraction) ? ($aiInteraction['transcript_hash'] ?? null) : hash('sha256', $text),
            ]);
        } catch (Throwable $exception) {
            $this->recordVoiceDispatchResult($capture, [
                'status' => 'ai_interaction_dispatch_failed',
                'dispatched' => false,
                'error_class' => $exception::class,
                'error_message_hash' => hash('sha256', $exception->getMessage()),
                'transcript_hash' => hash('sha256', $text),
            ]);

            report($exception);
        }
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function recordVoiceDispatchResult(Capture $capture, array $result): void
    {
        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $metadata['voice_realtime_dispatch_result'] = $this->withoutNullValues([
            'schema_version' => 'atlas.capture.voice_realtime_dispatch_result.v1',
            'recorded_at' => now()->toJSON(),
            ...$result,
        ]);

        $capture->forceFill(['metadata' => $metadata])->saveQuietly();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutNullValues(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    public function failed(?Throwable $exception): void
    {
        $job = TranscriptionJob::query()
            ->with('capture')
            ->find($this->transcriptionJobId);

        if (! $job) {
            return;
        }

        $message = $exception?->getMessage() ?: 'Transcription failed.';

        $job->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $message,
        ]);

        if (! $job->capture) {
            return;
        }

        $job->capture->update([
            'transcription_status' => 'failed',
            'transcription_error' => $message,
        ]);

        $voicePayload = data_get($job->capture->metadata, 'voice_realtime_dispatch');
        if (is_array($voicePayload) && (bool) ($voicePayload['dispatch_to_ai'] ?? false)) {
            $this->recordVoiceDispatchResult($job->capture->refresh(), [
                'status' => 'transcription_failed',
                'dispatched' => false,
                'reason' => 'audio_transcription_failed_before_voice_turn',
                'session_id' => $voicePayload['session_id'] ?? 'capture_'.$job->capture->client_id,
                'turn_id' => $voicePayload['turn_id'] ?? 'capture_'.$job->capture->client_id,
                'audio_hash' => $job->capture->content_sha256,
                'error_class' => $exception ? $exception::class : null,
                'error_message_hash' => hash('sha256', $message),
            ]);
        }
    }
}
