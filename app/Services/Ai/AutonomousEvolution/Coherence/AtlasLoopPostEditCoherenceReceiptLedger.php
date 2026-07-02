<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Coherence;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only JSONL ledger of post-edit coherence scan receipts. NEVER mutates prior lines;
 * exposes ONLY append/read methods (no update/delete/truncate by design).
 */
final class AtlasLoopPostEditCoherenceReceiptLedger
{
    public const SCHEMA = 'atlas.loop.post_edit_coherence_receipt.v1';

    public function __construct(private readonly string $ledgerPath)
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $scannerFindings
     * @param  array<string,mixed>  $orphanFindings
     * @return array<string,mixed>
     */
    public function append(
        string $gitHeadSha,
        string $editSetSha,
        array $scannerFindings,
        array $orphanFindings,
        ?string $ranAt = null,
    ): array {
        $ranAt = $ranAt ?? gmdate('Y-m-d\TH:i:s\Z');
        $receipt = [
            'schema_version' => self::SCHEMA,
            'receipt_id' => 'coh_'.substr(hash('sha256', $gitHeadSha.'|'.$editSetSha.'|'.$ranAt.'|'.bin2hex(random_bytes(4))), 0, 24),
            'git_head_sha' => $gitHeadSha,
            'edit_set_sha' => $editSetSha,
            'scanner_findings' => $scannerFindings,
            'orphan_findings' => $orphanFindings,
            'ran_at' => $ranAt,
        ];
        ksort($receipt, SORT_STRING);

        (new JsonlReceiptStore($this->ledgerPath))->append($receipt);

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(int $limit): array
    {
        $rows = $this->readAll();
        if ($limit > 0) {
            return array_values(array_slice($rows, -$limit));
        }

        return $rows;
    }

    public function latestForEditSet(string $editSetSha): ?array
    {
        $matches = array_values(array_filter(
            $this->readAll(),
            static fn (array $r): bool => (string) ($r['edit_set_sha'] ?? '') === $editSetSha,
        ));

        return $matches === [] ? null : $matches[count($matches) - 1];
    }

    public function receiptById(string $receiptId): ?array
    {
        foreach ($this->readAll() as $row) {
            if ((string) ($row['receipt_id'] ?? '') === $receiptId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        return (new JsonlReceiptStore($this->ledgerPath))->replay();
    }
}
