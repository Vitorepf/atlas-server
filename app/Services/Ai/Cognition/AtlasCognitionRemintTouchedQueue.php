<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AtlasCognitionRemintTouchedQueue
{
    public const FIELD_COMMAND = 'command';
    public const FIELD_COMMAND_ARGS = 'command_args';
    public const SCHEMA_VERSION = 'atlas.cognition.remint_touched.queue_item.v1';

    public const ENABLED_CONFIG_KEY = 'atlas.cognition.remint_touched_enabled';

    public const DEFAULT_ENABLED = false;

    public const QUEUE_DISK_CONFIG_KEY = 'atlas.cognition.remint_touched_queue_disk';

    public const DEFAULT_QUEUE_DISK = 'local';

    public const QUEUE_PATH_CONFIG_KEY = 'atlas.cognition.remint_touched_queue_path';

    public const DEFAULT_QUEUE_PATH = 'atlas/cognition/remint-touched-queue.jsonl';

    public const MODE_OFF = 'off';

    public const MODE_DEFERRED_DISK_QUEUE = 'deferred_disk_queue';

    public const REASON_DISABLED = 'disabled';

    public const REASON_EMPTY_PATHS = 'empty_paths';

    public const REASON_QUEUE_PATH_EMPTY = 'queue_path_empty';

    public const REASON_QUEUE_WRITE_FAILED = 'queue_write_failed';

    public const REASON_QUEUED = 'queued';

    public const FIELD_QUEUED = 'queued';

    public const FIELD_ERROR = 'error';
    public const FIELD_REASON = 'reason';
    public const FIELD_MODE = 'mode';
    public const FIELD_PATHS = 'paths';
    public const FIELD_JSON = 'json';
    public const FIELD_METADATA = 'metadata';

    /**
     * @param  list<string>  $paths
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function enqueue(array $paths, string $taskPacketId, array $metadata = []): array
    {
        if (! (AiValueNormalizer::boolOrNull(config(self::ENABLED_CONFIG_KEY, self::DEFAULT_ENABLED)) ?? self::DEFAULT_ENABLED)) {
            return [
                self::FIELD_QUEUED => false,
                self::FIELD_REASON => self::REASON_DISABLED,
                self::FIELD_MODE => self::MODE_OFF,
            ];
        }

        $paths = $this->normalizePaths($paths);
        if ($paths === []) {
            return [
                self::FIELD_QUEUED => false,
                self::FIELD_REASON => self::REASON_EMPTY_PATHS,
                self::FIELD_MODE => self::MODE_DEFERRED_DISK_QUEUE,
            ];
        }

        $command = 'php artisan atlas:cognition:remint-touched --paths='.implode(',', $paths).' --json';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'queued_at' => Carbon::now()->toIso8601String(),
            'task_packet_id' => $taskPacketId,
            self::FIELD_PATHS => $paths,
            self::FIELD_COMMAND => $command,
            self::FIELD_COMMAND_ARGS => [
                self::FIELD_PATHS => $paths,
                self::FIELD_JSON => true,
            ],
            self::FIELD_METADATA => $metadata,
        ];

        try {
            $disk = AiValueNormalizer::trimmedStringOrNull(config(self::QUEUE_DISK_CONFIG_KEY, self::DEFAULT_QUEUE_DISK)) ?? self::DEFAULT_QUEUE_DISK;
            $path = AiValueNormalizer::trimmedStringOrNull(config(self::QUEUE_PATH_CONFIG_KEY, self::DEFAULT_QUEUE_PATH)) ?? '';
            if ($path === '') {
                return [
                    self::FIELD_QUEUED => false,
                    self::FIELD_REASON => self::REASON_QUEUE_PATH_EMPTY,
                    self::FIELD_MODE => self::MODE_DEFERRED_DISK_QUEUE,
                ];
            }
            Storage::disk($disk)->append(
                $path,
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            return [
                self::FIELD_QUEUED => false,
                self::FIELD_REASON => self::REASON_QUEUE_WRITE_FAILED,
                self::FIELD_MODE => self::MODE_DEFERRED_DISK_QUEUE,
                self::FIELD_ERROR => mb_substr($e->getMessage(), 0, 200),
            ];
        }

        return [
            self::FIELD_QUEUED => true,
            self::FIELD_REASON => self::REASON_QUEUED,
            self::FIELD_MODE => self::MODE_DEFERRED_DISK_QUEUE,
            'path_count' => count($paths),
            'queue_path' => AiValueNormalizer::trimmedStringOrNull(config(self::QUEUE_PATH_CONFIG_KEY, self::DEFAULT_QUEUE_PATH)) ?? self::DEFAULT_QUEUE_PATH,
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
            $p = ltrim(str_replace('\\', '/', AiValueNormalizer::trimmedStringOrNull($path) ?? ''), '/');
            if ($p !== '' && ! str_contains($p, '..')) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }
}
