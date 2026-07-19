<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;

/** Evidence emitted by the thin native runner for one manifest entry. */
final class NativeExecutionReceipt
{
    public const SCHEMA_V1 = 'atlas.rivals2.native_execution_receipt.v1';

    public const SCHEMA = 'atlas.rivals2.native_execution_receipt.v2';

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
        $schema = (string) ($data['schema_version'] ?? '');
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
        if (! in_array($schema, [self::SCHEMA_V1, self::SCHEMA], true)) {
            throw new InvalidArgumentException('rivals_native_receipt_schema_mismatch');
        }
        if ($schema === self::SCHEMA && ! array_key_exists('failure_reason', $data)) {
            throw new InvalidArgumentException('rivals_native_receipt_missing:failure_reason');
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
            if ($schema === self::SCHEMA && ($data[$stream]['present'] ?? false)) {
                $path = $data[$stream]['path'] ?? null;
                if (! is_string($path) || $path === '') {
                    throw new InvalidArgumentException("rivals_native_receipt_stream_path_required:{$stream}");
                }
                try {
                    RunPaths::assertRelativePath($path);
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException("rivals_native_receipt_stream_path_unsafe:{$stream}");
                }
            }
        }
        if ($schema === self::SCHEMA) {
            $reason = $data['failure_reason'];
            if ($data['status'] === 'success' && $reason !== null) {
                throw new InvalidArgumentException('rivals_native_receipt_success_reason_must_be_null');
            }
            if ($data['status'] !== 'success' && (! is_string($reason) || trim($reason) === '')) {
                throw new InvalidArgumentException('rivals_native_receipt_failure_reason_required');
            }
        } else {
            $data['schema_version'] = self::SCHEMA;
            $data['failure_reason'] = $data['status'] === 'success'
                ? null
                : 'legacy_native_receipt_without_failure_reason:'.$data['status'];
            foreach (['stdout', 'stderr'] as $stream) {
                $data[$stream]['path'] = ($data[$stream]['present'] ?? false)
                    ? 'native_execution_receipts/logs/'.$data['execution_id'].'.'.$stream.'.log'
                    : null;
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
