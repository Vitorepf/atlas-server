<?php

namespace App\Jobs;

use App\Models\TranscriptionJob;
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

        $job->capture?->update([
            'transcription_status' => 'failed',
            'transcription_error' => $message,
        ]);
    }
}
