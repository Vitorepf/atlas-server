<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Integration;

use RuntimeException;

/**
 * Composes a SINGLE top-level cycle receipt linking all 8 phase receipts emitted by
 * AtlasLoopLiveCycleOrchestrator. Produces a deterministic merkle-style root_hash (sha256 over binary
 * concat of phase hashes in canonical order) so tampering with ANY phase hash invalidates the root.
 *
 * INVARIANTS:
 *   - FAIL-CLOSED: refuses to compose if fewer than 8 phase receipts are supplied — never fabricates a
 *     zero-hash placeholder.
 *   - Sub-ledger links are recorded verbatim (projection_outcome, cycle_git_contract, impact_receipt).
 *   - Emits exactly ONE FACT envelope (cycle.receipt.composed) — caller persists.
 *   - Identical (cycle_id, ordered_phase_hashes, links) ⇒ byte-identical root_hash + envelope.
 */
final class AtlasLoopLiveCyclePhaseReceiptComposer
{
    public const FACT_NAME = 'cycle.receipt.composed';

    public const PHASE_COUNT = 8;

    /**
     * @param  list<string>  $orderedPhaseReceiptHashes  exactly 8 phase hashes in canonical phase order
     * @param  array{projection_outcome?:?string, cycle_git_contract?:?string, impact_receipt?:?string}  $subLedgerLinks
     * @return array{fact:string, cycle_id:string, root_hash:string, phase_receipt_hashes:list<string>, sub_ledger_links:array<string,?string>}
     */
    public function compose(string $cycleId, array $orderedPhaseReceiptHashes, array $subLedgerLinks = []): array
    {
        if ($cycleId === '') {
            throw new RuntimeException('cycle_id is required');
        }
        if (count($orderedPhaseReceiptHashes) !== self::PHASE_COUNT) {
            throw new RuntimeException(sprintf(
                'cycle receipt composer requires exactly %d phase receipts, got %d (fail-closed: no fabrication)',
                self::PHASE_COUNT,
                count($orderedPhaseReceiptHashes),
            ));
        }
        foreach ($orderedPhaseReceiptHashes as $i => $h) {
            if (! is_string($h) || $h === '') {
                throw new RuntimeException("phase receipt hash {$i} is missing — fail-closed");
            }
        }

        $rootHash = $this->merkleRoot($orderedPhaseReceiptHashes);

        return [
            'fact' => self::FACT_NAME,
            'cycle_id' => $cycleId,
            'root_hash' => $rootHash,
            'phase_receipt_hashes' => array_values(array_map('strval', $orderedPhaseReceiptHashes)),
            'sub_ledger_links' => [
                'projection_outcome' => isset($subLedgerLinks['projection_outcome']) ? (string) $subLedgerLinks['projection_outcome'] : null,
                'cycle_git_contract' => isset($subLedgerLinks['cycle_git_contract']) ? (string) $subLedgerLinks['cycle_git_contract'] : null,
                'impact_receipt' => isset($subLedgerLinks['impact_receipt']) ? (string) $subLedgerLinks['impact_receipt'] : null,
            ],
        ];
    }

    /**
     * Merkle-style root: hex-decode each phase hash, concat in canonical order, sha256 the binary blob.
     * (If a phase hash isn't valid hex, fall back to the raw UTF-8 bytes so the composer is still
     * deterministic in tests that pass arbitrary strings.)
     *
     * @param  list<string>  $hashes
     */
    private function merkleRoot(array $hashes): string
    {
        $blob = '';
        foreach ($hashes as $h) {
            $bin = @hex2bin($h);
            $blob .= ($bin === false || $bin === '') ? $h : $bin;
        }

        return hash('sha256', $blob);
    }
}
