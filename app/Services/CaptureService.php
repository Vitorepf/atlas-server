<?php

namespace App\Services;

use App\Jobs\ProcessAudioTranscription;
use App\Models\Capture;
use App\Models\TranscriptionJob;
use App\Support\Metadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class CaptureService
{
    public function __construct(private readonly CaptureFileStorage $files) {}

    public function create(array $data, ?UploadedFile $file = null): array
    {
        $existing = Capture::withTrashed()
            ->where('client_id', $data['client_id'])
            ->first();

        if ($existing) {
            return ['capture' => $existing, 'created' => false];
        }

        $storedFile = null;

        try {
            if ($file) {
                $storedFile = $this->files->store($file, $data['kind']);
            }

            $result = DB::transaction(function () use ($data, $storedFile): array {
                $capture = Capture::create([
                    'client_id' => $data['client_id'],
                    'kind' => $data['kind'],
                    'domain' => $data['domain'] ?? 'outro',
                    'content_text' => $data['content_text'] ?? null,
                    'content_file_path' => $storedFile['relative_path'] ?? null,
                    'content_duration_ms' => $data['content_duration_ms'] ?? null,
                    'content_size_bytes' => $storedFile['size_bytes'] ?? null,
                    'content_sha256' => $storedFile['sha256'] ?? null,
                    'content_mime_type' => $storedFile['mime_type'] ?? null,
                    'transcription_status' => $data['kind'] === 'audio' ? 'pending' : 'na',
                    'captured_at' => $data['captured_at'],
                    'captured_timezone' => $data['captured_timezone'],
                    'captured_lat' => $data['captured_lat'] ?? null,
                    'captured_lng' => $data['captured_lng'] ?? null,
                    'pre_capture_digital_context' => Metadata::forStorage($data['pre_capture_digital_context'] ?? []),
                    'metadata' => Metadata::forStorage($data['metadata'] ?? []),
                ]);

                $transcriptionJob = null;

                if ($capture->kind === 'audio') {
                    $transcriptionJob = TranscriptionJob::create([
                        'capture_id' => $capture->id,
                        'status' => 'queued',
                    ]);

                    if (config('atlas.transcription.enabled')) {
                        ProcessAudioTranscription::dispatch($transcriptionJob->id)
                            ->onQueue('transcription')
                            ->afterCommit();
                    }
                }

                return [
                    'capture' => $capture,
                    'transcription_job' => $transcriptionJob,
                    'created' => true,
                ];
            });
        } catch (Throwable $throwable) {
            $this->files->deleteIfCreated($storedFile);
            throw $throwable;
        }

        return $result;
    }
}
