<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AtlasCognitionRemintTouchedQueue
{
    public const SCHEMA_VERSION = 'atlas.cognition.remint_touched.queue_item.v1';

    /**
     * @param  list<string>  $paths
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function enqueue(array $paths, string $taskPacketId, array $metadata = []): array
    {
        if (! (bool) config('atlas.cognition.remint_touched_enabled', false)) {
            return [
                'queued' => false,
                'reason' => 'disabled',
                'mode' => 'off',
            ];
        }

        $paths = $this->normalizePaths($paths);
        if ($paths === []) {
            return [
                'queued' => false,
                'reason' => 'empty_paths',
                'mode' => 'deferred_disk_queue',
            ];
        }

        $command = 'php artisan atlas:cognition:remint-touched --paths='.implode(',', $paths).' --json';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'queued_at' => Carbon::now()->toIso8601String(),
            'task_packet_id' => $taskPacketId,
            'paths' => $paths,
            'command' => $command,
            'command_args' => [
                'paths' => $paths,
                'json' => true,
            ],
            'metadata' => $metadata,
        ];

        try {
            $disk = (string) config('atlas.cognition.remint_touched_queue_disk', 'local');
            $path = AiValueNormalizer::trimmedString(config('atlas.cognition.remint_touched_queue_path', 'atlas/cognition/remint-touched-queue.jsonl'));
            if ($path === '') {
                return [
                    'queued' => false,
                    'reason' => 'queue_path_empty',
                    'mode' => 'deferred_disk_queue',
                ];
            }
            Storage::disk($disk)->append(
                $path,
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            return [
                'queued' => false,
                'reason' => 'queue_write_failed',
                'mode' => 'deferred_disk_queue',
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }

        return [
            'queued' => true,
            'reason' => 'queued',
            'mode' => 'deferred_disk_queue',
            'path_count' => count($paths),
            'queue_path' => (string) config('atlas.cognition.remint_touched_queue_path', 'atlas/cognition/remint-touched-queue.jsonl'),
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $p = ltrim(str_replace('\\', '/', AiValueNormalizer::trimmedString($path)), '/');
            if ($p !== '' && ! str_contains($p, '..')) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }
}
