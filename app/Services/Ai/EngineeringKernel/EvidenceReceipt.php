<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

/**
 * Canonical receipt for one applicable evidence dimension.
 * It carries execution provenance rather than merely a green boolean and is
 * content-addressed so replay/freshness checks can bind it to final hashes.
 */
final readonly class EvidenceReceipt
{
    public const SCHEMA = 'atlas.engineering_kernel.evidence_receipt.v1';

    /** @param array<string,mixed> $inputs @param list<array<string,mixed>> $assertions */
    private function __construct(
        public string $dimension,
        public string $command,
        public string $tool,
        public string $toolVersion,
        public array $inputs,
        public array $assertions,
        public int $exitCode,
        public float $timeoutSeconds,
        public string $artifactHash,
        public string $scopeHash,
        public string $observerIdentity,
        public string $observedAt,
        public string $specHash,
        public string $worldHash,
        public string $fileHash,
        public string $receiptHash,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $receipt = new self(
            dimension: self::requiredString($data, 'dimension'),
            command: self::requiredString($data, 'command'),
            tool: self::requiredString($data, 'tool'),
            toolVersion: self::requiredString($data, 'tool_version'),
            inputs: self::requiredMap($data, 'inputs'),
            assertions: self::assertions($data['assertions'] ?? null),
            exitCode: self::integer($data, 'exit_code'),
            timeoutSeconds: self::number($data, 'timeout_seconds'),
            artifactHash: self::requiredHash($data, 'artifact_hash'),
            scopeHash: self::requiredHash($data, 'scope_hash'),
            observerIdentity: self::requiredString($data, 'observer_identity'),
            observedAt: self::requiredString($data, 'observed_at'),
            specHash: self::requiredHash($data, 'spec_hash'),
            worldHash: self::requiredHash($data, 'world_hash'),
            fileHash: self::requiredHash($data, 'file_hash'),
            receiptHash: self::requiredHash($data, 'receipt_hash'),
        );
        if (! hash_equals($receipt->receiptHash, $receipt->canonicalHash())) {
            throw new InvalidArgumentException('evidence_receipt_hash_mismatch');
        }

        return $receipt;
    }

    /** @param array<string,mixed> $inputs @param list<array<string,mixed>> $assertions */
    public static function issue(
        string $dimension,
        string $command,
        string $tool,
        string $toolVersion,
        array $inputs,
        array $assertions,
        int $exitCode,
        float $timeoutSeconds,
        string $artifactHash,
        string $scopeHash,
        string $observerIdentity,
        string $observedAt,
        string $specHash,
        string $worldHash,
        string $fileHash,
    ): self {
        $body = [
            'schema' => self::SCHEMA, 'dimension' => $dimension, 'command' => $command,
            'tool' => $tool, 'tool_version' => $toolVersion, 'inputs' => $inputs,
            'assertions' => $assertions, 'exit_code' => $exitCode,
            'timeout_seconds' => $timeoutSeconds, 'artifact_hash' => $artifactHash,
            'scope_hash' => $scopeHash, 'observer_identity' => $observerIdentity,
            'observed_at' => $observedAt, 'spec_hash' => $specHash,
            'world_hash' => $worldHash, 'file_hash' => $fileHash,
        ];

        return self::fromArray($body + ['receipt_hash' => CanonicalKernelPayload::hash($body)]);
    }

    public function canonicalHash(): string
    {
        return CanonicalKernelPayload::hash($this->body());
    }

    /** @param array<string,string> $finalHashes */
    public function bindsTo(array $finalHashes): bool
    {
        foreach (['spec_hash' => $this->specHash, 'world_hash' => $this->worldHash, 'file_hash' => $this->fileHash] as $key => $actual) {
            if (! isset($finalHashes[$key]) || ! hash_equals($actual, $finalHashes[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->body() + ['receipt_hash' => $this->receiptHash];
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        return [
            'schema' => self::SCHEMA, 'dimension' => $this->dimension, 'command' => $this->command,
            'tool' => $this->tool, 'tool_version' => $this->toolVersion, 'inputs' => $this->inputs,
            'assertions' => $this->assertions, 'exit_code' => $this->exitCode,
            'timeout_seconds' => $this->timeoutSeconds, 'artifact_hash' => $this->artifactHash,
            'scope_hash' => $this->scopeHash, 'observer_identity' => $this->observerIdentity,
            'observed_at' => $this->observedAt, 'spec_hash' => $this->specHash,
            'world_hash' => $this->worldHash, 'file_hash' => $this->fileHash,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('evidence_receipt_'.$key.'_required');
        }

        return $value;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function requiredMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value)) {
            throw new InvalidArgumentException('evidence_receipt_'.$key.'_required');
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private static function assertions(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('evidence_receipt_assertions_required');
        }
        foreach ($value as $assertion) {
            if (! is_array($assertion)) {
                throw new InvalidArgumentException('evidence_receipt_assertion_invalid');
            }
        }

        return array_values($value);
    }

    /** @param array<string,mixed> $data */
    private static function integer(array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException('evidence_receipt_'.$key.'_invalid');
        }

        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private static function number(array $data, string $key): float
    {
        if (! is_int($data[$key] ?? null) && ! is_float($data[$key] ?? null)) {
            throw new InvalidArgumentException('evidence_receipt_'.$key.'_invalid');
        }

        return (float) $data[$key];
    }

    /** @param array<string,mixed> $data */
    private static function requiredHash(array $data, string $key): string
    {
        $value = self::requiredString($data, $key);
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('evidence_receipt_'.$key.'_invalid_hash');
        }

        return $value;
    }
}
