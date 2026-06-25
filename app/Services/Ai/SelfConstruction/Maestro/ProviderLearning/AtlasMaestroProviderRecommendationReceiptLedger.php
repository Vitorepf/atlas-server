<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * Append-only audit ledger for provider-recommendation events emitted by the maestro engine.
 *
 * Each `record()` call computes a deterministic receipt_id = sha256(task_class|provider|sample_size
 * |success_rate|requested_at) and appends ONE JSON line to the per-instance JSONL file, unless a
 * receipt with the same receipt_id is already on disk — in which case the call is a no-op.
 *
 * Reconciliation: replaying the same receipts in the same order yields a byte-identical on-disk
 * file. Lookup is sorted by (requested_at ASC, receipt_id ASC).
 *
 * Provider-free: no external/non-Atlas package dependencies.
 */
final class AtlasMaestroProviderRecommendationReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.provider_recommendation_receipt.v1';

    public function __construct(private readonly string $ledgerPath)
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $recommendation
     *      {task_class, recommended_provider, sample_size, success_rate, give_back_rate?, tied_with?, tie_reason?, requested_at, requested_by}
     * @return array<string,mixed>  the receipt that was appended (or the existing one if dedup'd)
     */
    public function record(array $recommendation): array
    {
        $taskClass = (string) ($recommendation['task_class'] ?? '');
        $provider = (string) ($recommendation['recommended_provider'] ?? '');
        $sampleSize = (int) ($recommendation['sample_size'] ?? 0);
        $successRate = (float) ($recommendation['success_rate'] ?? 0);
        $giveBackRate = (float) ($recommendation['give_back_rate'] ?? 0);
        $tiedWith = array_values(array_map('strval', (array) ($recommendation['tied_with'] ?? [])));
        $tieReason = (string) ($recommendation['tie_reason'] ?? '');
        $requestedAt = (string) ($recommendation['requested_at'] ?? '');
        $requestedBy = (string) ($recommendation['requested_by'] ?? '');

        $receiptId = $this->receiptId($taskClass, $provider, $sampleSize, $successRate, $requestedAt);

        $receipt = [
            'schema' => self::SCHEMA,
            'receipt_id' => $receiptId,
            'task_class' => $taskClass,
            'recommended_provider' => $provider,
            'sample_size' => $sampleSize,
            'success_rate' => $successRate,
            'give_back_rate' => $giveBackRate,
            'tied_with' => $tiedWith,
            'tie_reason' => $tieReason,
            'requested_at' => $requestedAt,
            'requested_by' => $requestedBy,
        ];

        if ($this->isAlreadyRecorded($receiptId)) {
            return $receipt;
        }

        $line = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->ledgerPath, 'a');
        if ($handle === false) {
            throw new \RuntimeException('provider recommendation ledger: cannot open '.$this->ledgerPath.' for append');
        }
        try {
            fwrite($handle, $line."\n");
        } finally {
            fclose($handle);
        }

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receipts(): array
    {
        $rows = $this->readAll();
        $this->sortReceipts($rows);

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receiptsForClass(string $taskClass): array
    {
        $rows = array_values(array_filter(
            $this->readAll(),
            static fn (array $r): bool => (string) ($r['task_class'] ?? '') === $taskClass,
        ));
        $this->sortReceipts($rows);

        return $rows;
    }

    private function receiptId(string $taskClass, string $provider, int $sampleSize, float $successRate, string $requestedAt): string
    {
        $canonical = $taskClass.'|'.$provider.'|'.$sampleSize.'|'.$successRate.'|'.$requestedAt;

        return hash('sha256', $canonical);
    }

    private function isAlreadyRecorded(string $receiptId): bool
    {
        foreach ($this->readAll() as $row) {
            if ((string) ($row['receipt_id'] ?? '') === $receiptId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        $handle = @fopen($this->ledgerPath, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function sortReceipts(array &$rows): void
    {
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['requested_at'] ?? ''), (string) ($b['requested_at'] ?? ''));

            return $cmp !== 0 ? $cmp : strcmp((string) ($a['receipt_id'] ?? ''), (string) ($b['receipt_id'] ?? ''));
        });
    }
}
