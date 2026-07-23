<?php

namespace App\Services\Ai\Company\Ventures\Safety;

use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * K3 — atomic windowed spend reservation (Atlas Phase 0 keystone).
 *
 * The death-by-a-thousand-cuts fix: a window holds (cap, reserved); a
 * reservation is an ATOMIC conditional UPDATE (reserved + n <= cap), so many
 * concurrent small spends can never collectively breach the cap. Idempotent
 * per key. Replaces the non-atomic read-modify-write of BudgetEnvelopeService
 * at venture-spend call sites.
 *
 * Fail-closed: a reservation that cannot be proven within cap is REFUSED.
 */
class WindowedReservationService
{
    private const KINDS = ['hour', 'day', 'week', 'portfolio'];

    /**
     * Try to reserve `amountMicroUsd` against (scopeRef, windowKind, windowKey)
     * with the given cap. Returns true iff reserved within cap. Idempotent: the
     * same idempotencyKey reserves at most once.
     */
    public function reserve(
        string $scopeRef,
        string $windowKind,
        string $windowKey,
        int $capMicroUsd,
        int $amountMicroUsd,
        string $idempotencyKey,
    ): bool {
        if (! in_array($windowKind, self::KINDS, true)) {
            throw VentureFoundryException::invalidValue('spend_reservation', 'window_kind', "unknown [{$windowKind}]");
        }
        if ($amountMicroUsd < 0 || $capMicroUsd < 0) {
            throw VentureFoundryException::invalidValue('spend_reservation', 'amount', 'must be non-negative');
        }
        if (trim($idempotencyKey) === '') {
            throw VentureFoundryException::missingField('spend_reservation', 'idempotency_key');
        }

        return DB::transaction(function () use ($scopeRef, $windowKind, $windowKey, $capMicroUsd, $amountMicroUsd, $idempotencyKey) {
            // Idempotency: same key never double-reserves.
            $existing = DB::table('ai_venture_spend_reservations')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return true;
            }

            // Find-or-create the window row (cap pinned on first sight).
            $window = DB::table('ai_venture_spend_windows')
                ->where('scope_ref', $scopeRef)->where('window_kind', $windowKind)->where('window_key', $windowKey)
                ->first();
            if ($window === null) {
                $windowId = (string) Str::uuid();
                DB::table('ai_venture_spend_windows')->insert([
                    'id' => $windowId,
                    'scope_ref' => $scopeRef,
                    'window_kind' => $windowKind,
                    'window_key' => $windowKey,
                    'cap_microusd' => $capMicroUsd,
                    'reserved_microusd' => 0,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            } else {
                $windowId = $window->id;
            }

            // ATOMIC conditional reserve: SET reserved += n WHERE reserved + n <= cap.
            $affected = DB::table('ai_venture_spend_windows')
                ->where('id', $windowId)
                ->whereRaw('reserved_microusd + ? <= cap_microusd', [$amountMicroUsd])
                ->increment('reserved_microusd', $amountMicroUsd);

            if ($affected !== 1) {
                return false; // would breach cap — fail closed
            }

            DB::table('ai_venture_spend_reservations')->insert([
                'id' => (string) Str::uuid(),
                'window_id' => $windowId,
                'idempotency_key' => $idempotencyKey,
                'amount_microusd' => $amountMicroUsd,
                'created_at' => Carbon::now(),
            ]);

            return true;
        });
    }

    public function reservedMicroUsd(string $scopeRef, string $windowKind, string $windowKey): int
    {
        $row = DB::table('ai_venture_spend_windows')
            ->where('scope_ref', $scopeRef)->where('window_kind', $windowKind)->where('window_key', $windowKey)
            ->first();

        return $row === null ? 0 : (int) $row->reserved_microusd;
    }
}
