<?php

namespace App\Http\Controllers;

use App\Models\AiYoutubeIngestion;
use App\Models\TranscriptionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbConnected = true;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbConnected = false;
        }

        $transcriptionJobs = [
            'queued' => 0,
            'processing' => 0,
            'failed' => 0,
        ];

        if ($dbConnected && Schema::hasTable('transcription_jobs')) {
            $counts = TranscriptionJob::query()
                ->selectRaw('status, count(*) as aggregate')
                ->whereIn('status', array_keys($transcriptionJobs))
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            foreach ($transcriptionJobs as $status => $_) {
                $transcriptionJobs[$status] = (int) ($counts[$status] ?? 0);
            }
        }

        $storage = $this->storageHealth();
        $transcription = $this->transcriptionHealth();
        $youtube = $this->youtubeHealth($dbConnected);

        return response()->json([
            'status' => 'ok',
            'version' => config('app.version', '1.0.0'),
            'service' => 'atlas-server',
            'ts' => now()->toJSON(),
            'db_connected' => $dbConnected,
            'overall_ok' => $dbConnected && $storage['writable'],
            'checks' => [
                'database' => [
                    'ok' => $dbConnected,
                ],
                'storage' => $storage,
                'transcription' => $transcription,
                'youtube_ingestion' => $youtube,
                'transcription_jobs' => $transcriptionJobs,
                'scheduler' => [
                    'configured' => true,
                    'note' => 'Laravel scheduler must be running outside this HTTP process.',
                ],
                'queue' => [
                    'connection' => config('queue.default'),
                    'youtube_queue' => (string) config('atlas.youtube.queue', 'transcription'),
                    'transcription_queue' => 'transcription',
                    'note' => 'Queue worker must run composer queue or php artisan queue:work.',
                ],
            ],
        ]);
    }

    private function storageHealth(): array
    {
        try {
            $disk = Storage::disk('atlas');
            $probe = '.health/'.now()->format('YmdHisv').'-'.bin2hex(random_bytes(4)).'.txt';

            $disk->put($probe, 'ok');
            $writable = $disk->exists($probe);
            $disk->delete($probe);

            return [
                'ok' => $writable,
                'writable' => $writable,
                'path' => config('atlas.storage_path'),
                'error' => null,
            ];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'writable' => false,
                'path' => config('atlas.storage_path'),
                'error' => $throwable->getMessage(),
            ];
        }
    }

    private function transcriptionHealth(): array
    {
        $binPath = (string) config('atlas.transcription.bin_path');
        $modelPath = (string) config('atlas.transcription.model_path');
        $ffmpegPath = (string) config('atlas.transcription.ffmpeg_path');

        return [
            'enabled' => (bool) config('atlas.transcription.enabled'),
            'engine' => config('atlas.transcription.engine'),
            'language' => config('atlas.transcription.language'),
            'binary_path' => $binPath,
            'binary_exists' => is_file($binPath),
            'binary_executable' => is_executable($binPath),
            'model_path' => $modelPath,
            'model_exists' => is_file($modelPath),
            'ffmpeg_path' => $ffmpegPath,
            'ffmpeg_exists' => is_file($ffmpegPath),
            'ffmpeg_executable' => is_executable($ffmpegPath),
        ];
    }

    private function youtubeHealth(bool $dbConnected): array
    {
        $summary = [
            'ready' => 0,
            'processing' => 0,
            'failed' => 0,
            'unavailable' => 0,
        ];
        $recent = [];

        if ($dbConnected && Schema::hasTable('ai_youtube_ingestions')) {
            $counts = AiYoutubeIngestion::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            foreach ($summary as $status => $_) {
                $summary[$status] = (int) ($counts[$status] ?? 0);
            }
            $summary['unavailable'] = collect($counts)
                ->reject(fn (mixed $_, string $status): bool => in_array($status, ['ready', 'processing'], true))
                ->sum(fn (mixed $count): int => (int) $count);

            $recent = AiYoutubeIngestion::query()
                ->latest('last_ingested_at')
                ->limit(5)
                ->get(['video_id', 'title', 'channel', 'status', 'audio_fallback_status', 'chunk_count', 'transcript_chars', 'ingestion_ms', 'last_ingested_at'])
                ->map(fn (AiYoutubeIngestion $item): array => [
                    'video_id' => $item->video_id,
                    'title' => $item->title,
                    'channel' => $item->channel,
                    'status' => $item->status,
                    'audio_fallback_status' => $item->audio_fallback_status,
                    'chunk_count' => $item->chunk_count,
                    'transcript_chars' => $item->transcript_chars,
                    'ingestion_ms' => $item->ingestion_ms,
                    'last_ingested_at' => $item->last_ingested_at?->toJSON(),
                ])
                ->all();
        }

        $configuredYtDlp = (string) config('atlas.youtube.yt_dlp_binary', '');
        $resolvedYtDlp = $configuredYtDlp !== '' && is_file($configuredYtDlp) && is_executable($configuredYtDlp)
            ? $configuredYtDlp
            : (new ExecutableFinder)->find('yt-dlp');

        return [
            'enabled' => (bool) config('atlas.youtube.enabled', true),
            'data_api_enabled' => (bool) config('atlas.youtube.data_api_enabled', false),
            'defer_audio_fallback' => (bool) config('atlas.youtube.defer_audio_fallback', true),
            'audio_fallback_enabled' => (bool) config('atlas.youtube.audio_fallback_enabled', false) || (bool) config('atlas.transcription.enabled', false),
            'yt_dlp_binary' => $resolvedYtDlp ?: 'missing',
            'yt_dlp_configured_binary' => $configuredYtDlp !== '' ? $configuredYtDlp : null,
            'whisper_timeout_seconds' => (int) config('atlas.transcription.timeout_seconds', 3600),
            'processing_lock_minutes' => (int) config('atlas.youtube.processing_lock_minutes', 90),
            'audio_download_timeout_seconds' => (int) config('atlas.youtube.audio_download_timeout_seconds', 300),
            'audio_download_retries' => (int) config('atlas.youtube.audio_download_retries', 2),
            'caption_download_retries' => (int) config('atlas.youtube.caption_download_retries', 2),
            'summary' => $summary,
            'recent' => $recent,
        ];
    }
}
