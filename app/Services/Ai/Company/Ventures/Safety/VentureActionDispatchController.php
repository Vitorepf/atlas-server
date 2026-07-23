<?php

namespace App\Services\Ai\Company\Ventures\Safety;

use Illuminate\Support\Carbon;

/**
 * K5 — the single fail-closed pre-emission boundary (Atlas Phase 0 keystone).
 *
 * EVERY external venture action passes through decide() before it can leave the
 * machine. Ordered, fail-closed: any unresolved/ambiguous input BLOCKS. Default
 * posture (autonomy=suggest, no mandate) => SUGGEST everywhere = byte-identical
 * to today's propose-only behaviour. Irreversible classes NEVER auto-execute
 * without an operator mandate. Spend actions must clear the K3 windowed cap.
 *
 * decide() does not itself perform the action — it authorizes (allow), proposes
 * (suggest), or refuses (block). Spend is reserved atomically only when allowing.
 */
class VentureActionDispatchController
{
    public const ALLOW = 'allow';

    public const SUGGEST = 'suggest';

    public const BLOCK = 'block';

    public function __construct(private readonly WindowedReservationService $reservations) {}

    /**
     * @param  array<string,mixed>  $req  {action_class, venture_id, autonomy?, has_live_mandate?, amount_microusd?, spend_cap_microusd?, spend_window_kind?, spend_window_key?, idempotency_key?}
     * @return array{decision:string, reason:string, reserved:bool}
     */
    public function decide(array $req): array
    {
        // (0) classify — unknown class fails closed.
        $class = VentureActionClass::tryFrom((string) ($req['action_class'] ?? ''));
        if ($class === null) {
            return $this->block('unknown_action_class');
        }

        $autonomy = (string) ($req['autonomy'] ?? 'suggest'); // default = suggest (byte-identical-OFF)
        $hasMandate = (bool) ($req['has_live_mandate'] ?? false);

        // (1) irreversible classes require a live mandate — auto cannot bypass.
        if ($class->isIrreversible() && ! $hasMandate) {
            return $this->block('irreversible_requires_mandate');
        }

        // (2) decide execution mode (before reserving any spend).
        if ($hasMandate) {
            $mode = self::ALLOW;
        } elseif ($autonomy === 'auto' && ! $class->isIrreversible()) {
            $mode = self::ALLOW;
        } elseif ($autonomy === 'approve') {
            // approve = needs a per-action human ok; without a mandate it is not executable yet.
            return $this->block('needs_operator_approval');
        } else {
            $mode = self::SUGGEST; // propose only — nothing leaves the machine
        }

        // (3) only when actually allowing: reserve spend atomically under the K3 cap.
        $reserved = false;
        if ($mode === self::ALLOW && $class->isSpend()) {
            $amount = (int) ($req['amount_microusd'] ?? 0);
            $cap = (int) ($req['spend_cap_microusd'] ?? 0);
            $ok = $this->reservations->reserve(
                (string) ($req['venture_id'] ?? ''),
                (string) ($req['spend_window_kind'] ?? 'day'),
                (string) ($req['spend_window_key'] ?? Carbon::now()->format('Y-m-d')),
                $cap,
                $amount,
                (string) ($req['idempotency_key'] ?? ($class->value.':'.($req['venture_id'] ?? '').':'.Carbon::now()->timestamp)),
            );
            if (! $ok) {
                return $this->block('spend_cap_exceeded');
            }
            $reserved = true;
        }

        return ['decision' => $mode, 'reason' => $mode === self::ALLOW ? 'authorized' : 'proposed', 'reserved' => $reserved];
    }

    /**
     * @return array{decision:string, reason:string, reserved:bool}
     */
    private function block(string $reason): array
    {
        return ['decision' => self::BLOCK, 'reason' => $reason, 'reserved' => false];
    }
}
