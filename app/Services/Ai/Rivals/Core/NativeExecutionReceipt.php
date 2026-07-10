<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;

/** Evidence emitted by the thin native runner for one manifest entry. */
final class NativeExecutionReceipt
{
    public const SCHEMA = 'atlas.rivals2.native_execution_receipt.v1';

    private const STATUSES = [
        'success',
        'failure',
        'timeout',
        'cancelled',
        'budget_exhausted',
        'environment_failure',
    ];

    private function __construct(public readonly array $data) {}

    public static function fromArray(array $data): self
    {
        foreach ([
            'schema_version', 'run_id', 'execution_id', 'manifest_hash',
            'command_hash', 'expected_result_path', 'result_sha256', 'status',
            'exit_code', 'started_at', 'finished_at', 'wall_ms', 'cost_usd',
            'stdout', 'stderr', 'runner',
        ] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("rivals_native_receipt_missing:{$field}");
            }
        }
        if ($data['schema_version'] !== self::SCHEMA) {
            throw new InvalidArgumentException('rivals_native_receipt_schema_mismatch');
        }
        if (! in_array($data['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('rivals_native_receipt_invalid_status');
        }
        foreach (['manifest_hash', 'command_hash', 'result_sha256'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', (string) $data[$field])) {
                throw new InvalidArgumentException("rivals_native_receipt_invalid_hash:{$field}");
            }
        }
        if (! is_int($data['wall_ms']) || $data['wall_ms'] < 0 || ! is_numeric($data['cost_usd'])) {
            throw new InvalidArgumentException('rivals_native_receipt_invalid_metrics');
        }
        if ($data['exit_code'] !== null && ! is_int($data['exit_code'])) {
            throw new InvalidArgumentException('rivals_native_receipt_invalid_exit_code');
        }
        foreach (['stdout', 'stderr'] as $stream) {
            if (! is_array($data[$stream])
                || ! is_bool($data[$stream]['present'] ?? null)
                || (($data[$stream]['present'] ?? false)
                    && ! preg_match('/^[a-f0-9]{64}$/', (string) ($data[$stream]['sha256'] ?? '')))) {
                throw new InvalidArgumentException("rivals_native_receipt_invalid_stream:{$stream}");
            }
        }

        return new self($data);
    }

    public function persist(): string
    {
        $runId = (string) $this->data['run_id'];
        RunPaths::ensureDir(RunPaths::nativeReceiptsDir($runId));
        $path = RunPaths::nativeReceiptsDir($runId).'/'.$this->data['execution_id'].'.json';
        file_put_contents(
            $path,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $path;
    }

    /** @return list<self> */
    public static function loadAll(string $runId): array
    {
        $dir = RunPaths::nativeReceiptsDir($runId);
        if (! is_dir($dir)) {
            return [];
        }

        return array_map(
            fn (string $path): self => self::fromArray(
                json_decode((string) file_get_contents($path), true) ?? []
            ),
            glob($dir.'/*.json') ?: [],
        );
    }
}
