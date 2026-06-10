<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AOBG N4.F4 — the production {@see ActuationReceiptStore}: an APPEND-ONLY, provider-safe
 * audit trail of every actuate() attempt, written to `atlas_organism_actuations`.
 *
 * Every row records that Atlas was asked to actuate a proposal and that the outcome was
 * 'requires_operator' (the propose-only ceiling) — NEVER an executed action. So the operator
 * can prove, per domain, exactly what they were handed back and that NOTHING crossed into the
 * real world. The status command reads these via {@see recent()}.
 *
 * PROVIDER-SAFE + SENSITIVE: the row carries the proposal's stable ref + canonical domain +
 * the sensitive flag + a REDACTED instruction label + the gate status/ceiling. It NEVER stores
 * the on-machine payload, never source, never secrets. A sensitive proposal is stored
 * sensitive => true and stays on-machine.
 *
 * APPEND-ONLY: an actuation attempt is an event, not a state — re-actuating the same proposal
 * appends another receipt (the audit must show every attempt). The id is per-attempt unique.
 *
 * FAIL-OPEN: when the store is absent, returns recorded:false with an honest reason — NEVER
 * throws (an audit-store outage must not become a way to skip the gate) and NEVER fabricates.
 */
final class EvidenceLedgerActuationReceiptStore implements ActuationReceiptStore
{
    public const TABLE = 'atlas_organism_actuations';

    public function record(DomainProposal $proposal, array $result): array
    {
        $ref = $proposal->ref();
        if (! $this->storePresent()) {
            return ['recorded' => false, 'receipt_ref' => $ref, 'reason' => 'store_missing'];
        }

        // Per-attempt unique receipt id (append-only audit — every attempt is its own event).
        $receiptId = 'actr:'.$proposal->domain.':'.substr(
            hash('sha256', $ref.'|'.microtime(true).'|'.random_int(0, PHP_INT_MAX)),
            0,
            40,
        );

        $status = (string) ($result['status'] ?? 'requires_operator');
        $instructions = AtlasSecurity::redactString((string) ($result['instructions'] ?? ''));
        $ceiling = (string) ($result['ceiling'] ?? AbstractDomainActuator::CEILING);

        try {
            DB::table(self::TABLE)->insert([
                'id' => mb_substr($receiptId, 0, 240),
                'proposal_ref' => mb_substr($ref, 0, 240),
                'domain' => mb_substr($proposal->domain, 0, 40),
                'sensitive' => $proposal->sensitive,
                // ALWAYS 'requires_operator' — the propose-only ceiling, recorded per attempt.
                'status' => mb_substr($status, 0, 32),
                'instructions' => mb_substr($instructions, 0, 2000),
                'ceiling' => mb_substr($ceiling, 0, 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            return ['recorded' => false, 'receipt_ref' => $ref, 'reason' => 'store_error'];
        }

        return ['recorded' => true, 'receipt_ref' => $receiptId];
    }

    public function recent(?string $domain = null, int $limit = 50): array
    {
        if (! $this->storePresent()) {
            return [];
        }

        try {
            $q = DB::table(self::TABLE)->orderByDesc('created_at')->limit(max(1, $limit));
            if ($domain !== null && trim($domain) !== '') {
                $q->where('domain', trim($domain));
            }
            $rows = $q->get(['id', 'proposal_ref', 'domain', 'sensitive', 'status', 'instructions', 'ceiling', 'created_at']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'receipt_ref' => (string) $row->id,
                'proposal_ref' => (string) $row->proposal_ref,
                'domain' => (string) $row->domain,
                'sensitive' => (bool) $row->sensitive,
                'status' => (string) $row->status,
                'instructions' => (string) $row->instructions,
                'ceiling' => (string) $row->ceiling,
                'ts' => (string) $row->created_at,
            ];
        }

        return $out;
    }

    private function storePresent(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }
}
