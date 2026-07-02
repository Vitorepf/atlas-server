<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use DomainException;
use RuntimeException;
use Throwable;

final class AtlasLoopAutopoieticConstitutionAuditReceiptLedger
{
    public function __construct(
        private readonly ?string $path = null,
    ) {
    }

    public function append(AutopoieticConstitutionReceipt $receipt): void
    {
        try {
            // Duplicate/ordering checks run INSIDE the store's exclusive lock.
            (new JsonlReceiptStore($this->path()))->appendWith(function (?string $lastLine) use ($receipt): array {
                $this->assertAppendable($receipt);

                return (array) json_decode(
                    (string) json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    true,
                );
            });
        } catch (DomainException|RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
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
        return (new JsonlReceiptStore($this->path()))->replay();
    }
}
