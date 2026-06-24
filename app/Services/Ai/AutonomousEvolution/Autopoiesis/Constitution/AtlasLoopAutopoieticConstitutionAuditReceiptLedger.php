<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

use DomainException;
use RuntimeException;

final class AtlasLoopAutopoieticConstitutionAuditReceiptLedger
{
    public function __construct(
        private readonly ?string $path = null,
    ) {
    }

    public function append(AutopoieticConstitutionReceipt $receipt): void
    {
        $this->assertAppendable($receipt);
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create constitution receipt ledger directory: '.$dir);
        }

        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new RuntimeException('Unable to open constitution receipt ledger: '.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock constitution receipt ledger: '.$path);
            }

            fwrite($handle, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<AutopoieticConstitutionReceipt>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): AutopoieticConstitutionReceipt => AutopoieticConstitutionReceipt::fromArray($row),
            $this->rows(),
        );
    }

    public function findByReceiptId(string $id): ?AutopoieticConstitutionReceipt
    {
        foreach ($this->all() as $receipt) {
            if ($receipt->receiptId === $id) {
                return $receipt;
            }
        }

        return null;
    }

    public function path(): string
    {
        return $this->path ?? storage_path('app/atlas/autopoiesis/constitution_receipts.jsonl');
    }

    private function assertAppendable(AutopoieticConstitutionReceipt $receipt): void
    {
        if ($receipt->receiptId === '') {
            throw new DomainException('constitution_receipt_id_required');
        }

        $last = null;
        foreach ($this->all() as $existing) {
            if ($existing->receiptId === $receipt->receiptId) {
                throw new DomainException('constitution_receipt_id_duplicate');
            }

            $last = $existing->receiptId;
        }

        if ($last !== null && strcmp($receipt->receiptId, $last) <= 0) {
            throw new DomainException('constitution_receipt_id_out_of_order');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
