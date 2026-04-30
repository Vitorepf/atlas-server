<?php

namespace App\Http\Controllers;

use App\Models\TranscriptionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
                'transcription_jobs' => $transcriptionJobs,
                'scheduler' => [
                    'configured' => true,
                    'note' => 'Laravel scheduler must be running outside this HTTP process.',
                ],
                'queue' => [
                    'connection' => config('queue.default'),
                    'transcription_queue' => 'transcription',
                    'note' => 'Queue worker must run composer queue or php artisan queue:work.',
                ],
            ],
        ]);
    }

    private function storageHealth(): array
    {
        $disk = Storage::disk('atlas');
        $probe = '.health/'.now()->format('YmdHisv').'-'.bin2hex(random_bytes(4)).'.txt';

        try {
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
}
