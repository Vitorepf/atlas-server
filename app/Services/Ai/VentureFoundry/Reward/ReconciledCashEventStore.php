<?php

namespace App\Services\Ai\VentureFoundry\Reward;

use App\Models\AiReconciledCashEvent;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\VentureFoundryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * K1 — the single writer of reconciled cash truth (Atlas Phase 0 keystone).
 *
 * Only externally-reconciled cash (payment_processor | bank | external_reconciled)
 * is admissible — enforced here AND at the DB CHECK (defense in depth). A
 * self-reported source can never be credited. Refunds/chargebacks/disputes are
 * recorded as NEGATIVE amounts; reward counts only the SETTLED net (after the
 * clawback window clears), so a reversal can flip a venture's verdict.
 *
 * Anti-Goodhart: the actor that emits an action never writes the observation
 * that credits it — this store accepts only verified-source events keyed by a
 * distinct, dereferenceable external_ref (idempotent).
 */
class ReconciledCashEventStore
{
    /**
     * Record a reconciled cash event. Idempotent on (source, external_ref).
     *
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiReconciledCashEvent
    {
        $source = (string) ($args['source'] ?? '');
        if (! in_array($source, AiReconciledCashEvent::SOURCES, true)) {
            // Defense in depth; the DB CHECK is the real boundary.
            throw VentureFoundryException::invalidValue('reconciled_cash', 'source', "non-reconciled source [{$source}] cannot be credited");
        }

        $kind = (string) ($args['event_kind'] ?? 'credit');
        if (! in_array($kind, AiReconciledCashEvent::KINDS, true)) {
            throw VentureFoundryException::invalidValue('reconciled_cash', 'event_kind', "unknown kind [{$kind}]");
        }

        $externalRef = trim((string) ($args['external_ref'] ?? ''));
        if ($externalRef === '') {
            throw VentureFoundryException::missingField('reconciled_cash', 'external_ref');
        }

        $ventureId = (string) ($args['venture_id'] ?? '');
        if ($ventureId === '') {
            throw VentureFoundryException::missingField('reconciled_cash', 'venture_id');
        }

        if (! array_key_exists('amount_cents', $args) || ! is_numeric($args['amount_cents'])) {
            throw VentureFoundryException::missingField('reconciled_cash', 'amount_cents');
        }
        $amountCents = (int) $args['amount_cents'];
        // Reversals must reduce, not add.
        if (in_array($kind, ['refund', 'chargeback', 'dispute', 'failed_renewal'], true) && $amountCents > 0) {
            $amountCents = -$amountCents;
        }

        // Idempotency: the same source receipt is counted once.
        $existing = AiReconciledCashEvent::query()
            ->where('source', $source)
            ->where('external_ref', $externalRef)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $occurredAt = isset($args['occurred_at']) ? Carbon::parse((string) $args['occurred_at']) : Carbon::now();
        $horizon = (int) ($args['settlement_horizon_days'] ?? 0);
        // settled_at is null until the clawback window clears; horizon 0 = settled now.
        $settledAt = $horizon <= 0 ? $occurredAt : null;
        if (array_key_exists('settled_at', $args) && $args['settled_at'] !== null) {
            $settledAt = Carbon::parse((string) $args['settled_at']);
        }

        $uuid = (string) Str::uuid();

        return AiReconciledCashEvent::query()->create([
            'uuid' => $uuid,
            'venture_id' => $ventureId,
            'source' => $source,
            'event_kind' => $kind,
            'external_ref' => $externalRef,
            'amount_cents' => $amountCents,
            'currency' => (string) ($args['currency'] ?? 'BRL'),
            'occurred_at' => $occurredAt,
            'settlement_horizon_days' => max(0, $horizon),
            'settled_at' => $settledAt,
            'reverses_external_ref' => $args['reverses_external_ref'] ?? null,
            'raw_payload' => $args['raw_payload'] ?? null,
            'event_hash' => StrategyCanonicalHash::sha256([
                'uuid' => $uuid,
                'venture_id' => $ventureId,
                'source' => $source,
                'external_ref' => $externalRef,
                'amount_cents' => $amountCents,
                'occurred_at' => $occurredAt->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Mark a previously-recorded event as settled (clawback window cleared).
     */
    public function settle(string $source, string $externalRef, ?Carbon $when = null): bool
    {
        $event = AiReconciledCashEvent::query()
            ->where('source', $source)
            ->where('external_ref', $externalRef)
            ->first();
        if ($event === null) {
            return false;
        }
        $event->settled_at = $when ?? Carbon::now();
        $event->save();

        return true;
    }

    /**
     * SETTLED net cents for a venture in [from, to] — the only figure that pays.
     * Unsettled (clawback-pending) cash contributes ZERO.
     */
    public function settledNetCentsForVenture(string $ventureId, Carbon $from, Carbon $to): int
    {
        return (int) AiReconciledCashEvent::query()
            ->where('venture_id', $ventureId)
            ->whereNotNull('settled_at')
            ->whereBetween('settled_at', [$from, $to])
            ->sum('amount_cents');
    }

    /**
     * Per calendar month (Y-m) settled net cents — what the sustained-MRR
     * evaluator reads. Only settled events count.
     *
     * @return array<string,int>
     */
    public function monthlySettledNetCents(string $ventureId): array
    {
        $events = AiReconciledCashEvent::query()
            ->where('venture_id', $ventureId)
            ->whereNotNull('settled_at')
            ->orderBy('settled_at')
            ->get(['settled_at', 'amount_cents']);

        $byMonth = [];
        foreach ($events as $event) {
            $month = $event->settled_at?->format('Y-m');
            if ($month === null) {
                continue;
            }
            $byMonth[$month] = ($byMonth[$month] ?? 0) + (int) $event->amount_cents;
        }
        ksort($byMonth);

        return $byMonth;
    }
}
