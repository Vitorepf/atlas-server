<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Frozen;

use RuntimeException;

final class AtlasLoopFrozenContractReceiptLedger
{
    public function __construct(
        private readonly ?string $ledgerPath = null,
        private readonly ?string $manifestPath = null,
    ) {}

    /**
     * @param  array<string,mixed>  $verdictStruct
     * @return array{ts:string,source:'audit',fqcn:list<string>,verdict_or_facts:array<string,mixed>,manifest_sha:string}
     */
    public function recordAudit(array $verdictStruct): array
    {
        /** @var array{ts:string,source:'audit',fqcn:list<string>,verdict_or_facts:array<string,mixed>,manifest_sha:string} $receipt */
        $receipt = $this->receipt('audit', $verdictStruct);
        $this->append($receipt);

        return $receipt;
    }

    /**
     * @param  list<array<string,mixed>>  $factsStruct
     * @return array{ts:string,source:'drift',fqcn:list<string>,verdict_or_facts:list<array<string,mixed>>,manifest_sha:string}
     */
    public function recordDrift(array $factsStruct): array
    {
        /** @var array{ts:string,source:'drift',fqcn:list<string>,verdict_or_facts:list<array<string,mixed>>,manifest_sha:string} $receipt */
        $receipt = $this->receipt('drift', $factsStruct);
        $this->append($receipt);

        return $receipt;
    }

    /**
     * @param  'audit'|'drift'  $source
     * @param  array<string,mixed>|list<array<string,mixed>>  $payload
     * @return array{ts:string,source:'audit'|'drift',fqcn:list<string>,verdict_or_facts:array<string,mixed>|list<array<string,mixed>>,manifest_sha:string}
     */
    private function receipt(string $source, array $payload): array
    {
        return [
            'ts' => gmdate('c'),
            'source' => $source,
            'fqcn' => $this->extractFqcns($payload),
            'verdict_or_facts' => $payload,
            'manifest_sha' => $this->manifestSha(),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function append(array $receipt): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create frozen contract receipt ledger directory.');
        }

        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path, $encoded."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append frozen contract receipt ledger line.');
        }
    }

    private function path(): string
    {
        return $this->ledgerPath ?? storage_path('atlas/loop/frozen-contract-receipts.jsonl');
    }

    private function manifestSha(): string
    {
        $path = $this->manifestPath ?? __DIR__.'/contracts.manifest.json';
        if (! is_file($path)) {
            return '';
        }

        return (string) hash_file('sha256', $path);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     * @return list<string>
     */
    private function extractFqcns(array $payload): array
    {
        $seen = [];
        $this->collectFqcns($payload, $seen);

        return array_keys($seen);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     * @param  array<string,true>  $seen
     */
    private function collectFqcns(array $payload, array &$seen): void
    {
        foreach ($payload as $key => $value) {
            if ($key === 'fqcn' && is_string($value) && $value !== '') {
                $seen[$value] = true;
                continue;
            }

            if (is_array($value)) {
                $this->collectFqcns($value, $seen);
            }
        }
    }
}
