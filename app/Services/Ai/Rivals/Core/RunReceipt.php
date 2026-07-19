<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use InvalidArgumentException;

/**
 * Recibo por case × arm × repetition: status, tempo, tokens, custo e artifacts
 * hash-pinned. Sem receipt não há claim; receipt de falha é medição válida.
 */
class RunReceipt
{
    private function __construct(public readonly array $data) {}

    public static function fromArray(array $data): self
    {
        $schema = (string) ($data['schema_version'] ?? '');
        if ($schema === SchemaContract::RUN_RECEIPT_V1) {
            $violations = SchemaContract::validate($data, SchemaContract::RUN_RECEIPT_V1);
            if ($violations !== []) {
                throw new InvalidArgumentException('rivals_invalid_receipt: '.implode(',', $violations));
            }
            $data['claim_tier'] = ClaimTier::forLegacyReceipt($data);
            $data['harness_only'] = ($data['claim_tier'] ?? null) === ClaimTier::HARNESS;
            $data['failure_class'] = self::defaultFailureClass((string) $data['status']);
            $data['field_presence'] = self::defaultFieldPresence($data);
            $data['schema_version'] = SchemaContract::RUN_RECEIPT_V2;
            $schema = SchemaContract::RUN_RECEIPT_V2;
        }

        if ($schema === SchemaContract::RUN_RECEIPT_V2) {
            $violations = SchemaContract::validate($data, SchemaContract::RUN_RECEIPT_V2);
            if ($violations !== []) {
                throw new InvalidArgumentException('rivals_invalid_receipt: '.implode(',', $violations));
            }
            $data['schema_version'] = SchemaContract::RUN_RECEIPT;
            $data['failure_reason'] = self::legacyFailureReason($data);
        }

        $violations = SchemaContract::validate($data, SchemaContract::RUN_RECEIPT);
        if ($violations !== []) {
            throw new InvalidArgumentException('rivals_invalid_receipt: '.implode(',', $violations));
        }

        return new self($data);
    }

    public function key(): string
    {
        return "{$this->data['case_id']}|{$this->data['arm_id']}|{$this->data['repetition']}";
    }

    public function append(): void
    {
        $runId = $this->data['run_id'];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::receiptsPath($runId),
            json_encode($this->data, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /** @return array<int, self> */
    public static function loadAll(string $runId): array
    {
        $path = RunPaths::receiptsPath($runId);
        if (! is_file($path)) {
            return [];
        }
        $receipts = [];
        foreach (array_filter(explode(PHP_EOL, file_get_contents($path))) as $line) {
            $receipts[] = self::fromArray(json_decode($line, true) ?? []);
        }

        return $receipts;
    }

    public static function defaultFailureClass(string $status): ?string
    {
        return match ($status) {
            'success' => null,
            'timeout' => FailureClass::TIMEOUT,
            'failure' => FailureClass::MODEL,
            default => FailureClass::INVALID_RESULT,
        };
    }

    public static function defaultFailureReason(string $status, ?string $failureClass): ?string
    {
        if ($status === 'success') {
            return null;
        }

        return match ($failureClass) {
            FailureClass::TIMEOUT => 'execution_timeout',
            FailureClass::ENVIRONMENT => 'environment_failure_without_native_reason',
            FailureClass::MODEL => 'benchmark_verdict_not_resolved',
            FailureClass::INVALID_RESULT => 'benchmark_result_invalid_or_incomplete',
            default => 'benchmark_execution_failed:'.$status,
        };
    }

    private static function legacyFailureReason(array $data): ?string
    {
        if (($data['status'] ?? null) === 'success') {
            return null;
        }

        $metadataReason = data_get($data, 'metadata.native.runner_reason');
        if (is_string($metadataReason) && trim($metadataReason) !== '') {
            return trim($metadataReason);
        }

        return 'legacy_receipt_without_failure_reason:'
            .((string) ($data['failure_class'] ?? 'unknown_failure'));
    }

    /** @return array<string, array{present: bool, reason: ?string}> */
    public static function defaultFieldPresence(array $data): array
    {
        $presence = [];
        foreach (['wall_ms', 'tokens_in', 'tokens_out', 'cost_usd'] as $field) {
            $present = array_key_exists($field, $data) && is_numeric($data[$field]);
            $presence[$field] = [
                'present' => $present,
                'reason' => $present ? null : 'field_not_reported',
            ];
        }

        return $presence;
    }
}
