<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback;

use Generator;
use RuntimeException;

final class AtlasAaelExecutionRollbackReceiptLedger
{
    public const SCHEMA = 'atlas.aael.execution.rollback_receipt_ledger.v1';
    private const GENESIS = 'GENESIS';

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt): array
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('aael_rollback_receipt_ledger_directory_create_failed');
        }

        $handle = fopen($path, 'ab+');
        if ($handle === false) {
            throw new RuntimeException('aael_rollback_receipt_ledger_open_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('aael_rollback_receipt_ledger_lock_failed');
            }

            $verification = $this->verifyChain();
            if (($verification['ok'] ?? false) !== true) {
                $this->quarantineCorruptLedger();

                throw new RuntimeException('aael_rollback_receipt_ledger_corrupt_quarantined');
            }

            $normalized = $this->normalizeReceipt($receipt);
            $prevHash = (string) ($verification['head_hash'] ?? self::GENESIS);
            $canonicalPayload = $this->canonicalPayload($normalized);
            $normalized['prev_hash'] = $prevHash;
            $normalized['receipt_hash'] = hash('sha256', $this->encodeCanonical($canonicalPayload).$prevHash);

            $line = $this->encodeCanonical($normalized)."\n";
            $written = fwrite($handle, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new RuntimeException('aael_rollback_receipt_ledger_append_failed');
            }

            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }

            return $normalized;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function list(?string $executionId = null, int $limit = 100): Generator
    {
        $path = $this->path();
        if (! is_file($path) || $limit <= 0) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('aael_rollback_receipt_ledger_read_open_failed');
        }

        try {
            $yielded = 0;
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded)) {
                    continue;
                }

                if ($executionId !== null && (string) ($decoded['execution_id'] ?? '') !== $executionId) {
                    continue;
                }

                yield $decoded;
                $yielded++;

                if ($yielded >= $limit) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{
     *   ok:bool,
     *   bad_line:?int,
     *   reason:?string,
     *   head_hash:string
     * }
     */
    public function verifyChain(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [
                'ok' => true,
                'bad_line' => null,
                'reason' => null,
                'head_hash' => self::GENESIS,
            ];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('aael_rollback_receipt_ledger_verify_open_failed');
        }

        try {
            $lineNumber = 0;
            $previousHash = self::GENESIS;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded)) {
                    return [
                        'ok' => false,
                        'bad_line' => $lineNumber,
                        'reason' => 'invalid_json_line',
                        'head_hash' => $previousHash,
                    ];
                }

                $prevHash = (string) ($decoded['prev_hash'] ?? '');
                if ($prevHash !== $previousHash) {
                    return [
                        'ok' => false,
                        'bad_line' => $lineNumber,
                        'reason' => 'prev_hash_mismatch',
                        'head_hash' => $previousHash,
                    ];
                }

                $recordedHash = (string) ($decoded['receipt_hash'] ?? '');
                $computedHash = hash('sha256', $this->encodeCanonical($this->canonicalPayload($decoded)).$prevHash);
                if ($recordedHash === '' || $computedHash !== $recordedHash) {
                    return [
                        'ok' => false,
                        'bad_line' => $lineNumber,
                        'reason' => 'receipt_hash_mismatch',
                        'head_hash' => $previousHash,
                    ];
                }

                $previousHash = $recordedHash;
            }

            if (! feof($handle)) {
                return [
                    'ok' => false,
                    'bad_line' => $lineNumber + 1,
                    'reason' => 'truncated_tail',
                    'head_hash' => $previousHash,
                ];
            }

            return [
                'ok' => true,
                'bad_line' => null,
                'reason' => null,
                'head_hash' => $previousHash,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function normalizeReceipt(array $receipt): array
    {
        $normalized = [
            'schema_version' => (string) ($receipt['schema_version'] ?? self::SCHEMA),
            'execution_id' => trim((string) ($receipt['execution_id'] ?? '')),
            'triggered_at_utc' => $this->normalizeUtc((string) ($receipt['triggered_at_utc'] ?? '')),
            'trigger_reason' => trim((string) ($receipt['trigger_reason'] ?? '')),
            'manifest_sha' => trim((string) ($receipt['manifest_sha'] ?? '')),
            'target_count' => $this->normalizeCount($receipt['target_count'] ?? null),
            'restored_count' => $this->normalizeCount($receipt['restored_count'] ?? null),
            'skipped_count' => $this->normalizeCount($receipt['skipped_count'] ?? null),
            'status' => trim((string) ($receipt['status'] ?? '')),
            'per_target' => $this->normalizePerTarget($receipt['per_target'] ?? []),
            'evidence_ids' => $this->normalizeStringList($receipt['evidence_ids'] ?? []),
        ];

        $this->assertSchema($normalized);

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function canonicalPayload(array $receipt): array
    {
        $payload = $receipt;
        unset($payload['prev_hash'], $payload['receipt_hash']);

        return $this->sortRecursive($payload);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }

            $items[] = $trimmed;
        }

        return array_values($items);
    }

    /**
     * @param  mixed  $value
     * @return list<array{path:string,pre_sha:string,post_sha:string,action:string}>
     */
    private function normalizePerTarget(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $targets = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $targets[] = [
                'path' => trim((string) ($row['path'] ?? '')),
                'pre_sha' => trim((string) ($row['pre_sha'] ?? '')),
                'post_sha' => trim((string) ($row['post_sha'] ?? '')),
                'action' => trim((string) ($row['action'] ?? '')),
            ];
        }

        return $targets;
    }

    private function normalizeUtc(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function normalizeCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return -1;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function assertSchema(array $receipt): void
    {
        if ((string) ($receipt['schema_version'] ?? '') !== self::SCHEMA) {
            throw new AtlasAaelRollbackReceiptSchemaViolation('schema_version');
        }
        if ((string) ($receipt['execution_id'] ?? '') === '') {
            throw new AtlasAaelRollbackReceiptSchemaViolation('execution_id');
        }
        if ((string) ($receipt['triggered_at_utc'] ?? '') === '') {
            throw new AtlasAaelRollbackReceiptSchemaViolation('triggered_at_utc');
        }
        if (! in_array((string) ($receipt['trigger_reason'] ?? ''), ['post_verify_failed', 'operator_request', 'partial_restore'], true)) {
            throw new AtlasAaelRollbackReceiptSchemaViolation('trigger_reason');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['manifest_sha'] ?? ''))) {
            throw new AtlasAaelRollbackReceiptSchemaViolation('manifest_sha');
        }
        if (! in_array((string) ($receipt['status'] ?? ''), ['restored', 'already_restored', 'partial', 'refused'], true)) {
            throw new AtlasAaelRollbackReceiptSchemaViolation('status');
        }
        foreach (['target_count', 'restored_count', 'skipped_count'] as $field) {
            if (! is_int($receipt[$field]) || $receipt[$field] < 0) {
                throw new AtlasAaelRollbackReceiptSchemaViolation($field);
            }
        }

        foreach ((array) ($receipt['per_target'] ?? []) as $row) {
            if ((string) ($row['path'] ?? '') === ''
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($row['pre_sha'] ?? ''))
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($row['post_sha'] ?? ''))
                || ! in_array((string) ($row['action'] ?? ''), ['restored', 'skipped', 'refused'], true)) {
                throw new AtlasAaelRollbackReceiptSchemaViolation('per_target');
            }
        }
    }

    private function quarantineCorruptLedger(): void
    {
        $path = $this->path();
        if (! is_file($path)) {
            return;
        }

        $quarantinePath = $path.'.quarantine';
        @unlink($quarantinePath);
        if (! rename($path, $quarantinePath)) {
            throw new RuntimeException('aael_rollback_receipt_ledger_quarantine_failed');
        }
    }

    private function path(): string
    {
        return $this->path ?? storage_path('atlas/aael/rollback/ledger.jsonl');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encodeCanonical(array $payload): string
    {
        return (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}

final class AtlasAaelRollbackReceiptSchemaViolation extends RuntimeException {}
