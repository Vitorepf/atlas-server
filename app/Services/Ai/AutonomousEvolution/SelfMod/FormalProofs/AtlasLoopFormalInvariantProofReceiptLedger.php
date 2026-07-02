<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;
use Throwable;

final class AtlasLoopFormalInvariantProofReceiptLedger
{
    private int $duplicateSeen = 0;

    public function __construct(
        private readonly string $basePath = '',
    ) {}

    /**
     * @param  array{
     *   invariant_id:string,
     *   verdict:string,
     *   witness_nodes?:list<array<string,mixed>>,
     *   unsupported_reason?:?string
     * }  $proofResult
     * @param  array{
     *   file_path:string,
     *   pre_sha:string,
     *   post_sha:string,
     *   prover_version:string,
     *   produced_at?:?string
     * }  $context
     * @return array<string,mixed>
     */
    public function record(array $proofResult, array $context): array
    {
        $receipt = $this->normalizeReceipt($proofResult, $context);
        $existing = null;
        try {
            // Duplicate receipt_id check runs INSIDE the store's exclusive lock; null return skips the write.
            (new JsonlReceiptStore($this->ledgerPath((string) $receipt['produced_at'])))
                ->appendWith(function (?string $lastLine) use ($receipt, &$existing): ?array {
                    $existing = $this->lookup((string) $receipt['receipt_id']);
                    if ($existing !== null) {
                        if ($this->canonicalJson($existing) !== $this->canonicalJson($receipt)) {
                            throw new RuntimeException('append_only_violation_existing_receipt_differs');
                        }

                        $this->duplicateSeen++;

                        return null;
                    }

                    // Round-trip so the stored line is byte-identical to canonicalJson($receipt).
                    return (array) json_decode($this->canonicalJson($receipt), true);
                });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $existing ?? $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(string $invariantId, int $limit = 50): array
    {
        $rows = array_values(array_filter(
            $this->readAll(),
            static fn (array $receipt): bool => (string) ($receipt['invariant_id'] ?? '') === $invariantId,
        ));

        return array_slice($rows, -max(0, $limit));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lookup(string $receiptId): ?array
    {
        foreach ($this->readAll() as $receipt) {
            if ((string) ($receipt['receipt_id'] ?? '') === $receiptId) {
                return $receipt;
            }
        }

        return null;
    }

    /**
     * @return array{duplicate_seen:int}
     */
    public function stats(): array
    {
        return ['duplicate_seen' => $this->duplicateSeen];
    }

    /**
     * @param  array<string,mixed>  $proofResult
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function normalizeReceipt(array $proofResult, array $context): array
    {
        $payload = [
            'produced_at' => $this->normalizeProducedAt((string) ($context['produced_at'] ?? gmdate('c'))),
            'invariant_id' => (string) ($proofResult['invariant_id'] ?? ''),
            'file_path' => (string) ($context['file_path'] ?? ''),
            'pre_sha' => (string) ($context['pre_sha'] ?? ''),
            'post_sha' => (string) ($context['post_sha'] ?? ''),
            'verdict' => (string) ($proofResult['verdict'] ?? ''),
            'witness_nodes' => array_values(is_array($proofResult['witness_nodes'] ?? null) ? $proofResult['witness_nodes'] : []),
            'unsupported_reason' => isset($proofResult['unsupported_reason']) ? (string) $proofResult['unsupported_reason'] : null,
            'prover_version' => (string) ($context['prover_version'] ?? ''),
        ];
        $payload['receipt_id'] = $this->deterministicUuid($payload);

        /** @var array<string,mixed> $canonicalPayload */
        $canonicalPayload = $this->sortRecursive($payload);

        return $canonicalPayload;
    }

    private function normalizeProducedAt(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? gmdate('c') : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function deterministicUuid(array $payload): string
    {
        unset($payload['receipt_id']);
        $hex = hash('sha256', $this->canonicalJson($payload));
        $timeLow = substr($hex, 0, 8);
        $timeMid = substr($hex, 8, 4);
        $timeHi = dechex((hexdec(substr($hex, 12, 4)) & 0x0fff) | 0x4000);
        $clockSeq = dechex((hexdec(substr($hex, 16, 4)) & 0x3fff) | 0x8000);
        $node = substr($hex, 20, 12);

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            $timeLow,
            $timeMid,
            str_pad($timeHi, 4, '0', STR_PAD_LEFT),
            str_pad($clockSeq, 4, '0', STR_PAD_LEFT),
            $node,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        $paths = glob($this->rootPath().'/*.jsonl') ?: [];
        sort($paths, SORT_STRING);

        $rows = [];
        foreach ($paths as $path) {
            array_push($rows, ...(new JsonlReceiptStore($path))->replay());
        }

        return $rows;
    }

    private function ledgerPath(string $producedAt): string
    {
        return $this->rootPath().'/'.substr($producedAt, 0, 10).'.jsonl';
    }

    private function rootPath(): string
    {
        return $this->basePath !== ''
            ? rtrim($this->basePath, '/')
            : storage_path('app/atlas/loop/formal-proofs');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
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
