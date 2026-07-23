<?php

namespace App\Services\Ai\Company\Ventures\Cost;

use App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore;
use Illuminate\Support\Carbon;

/**
 * K2↔K1 composition — honest runway/solvency read.
 *
 * Burn comes from the cost ledger (K2); cash from settled reconciled events
 * (K1). Returns an explicit status and NEVER fabricates: `unknown` when there
 * is no cost data, `no_burn` when burn is zero, `needs_fx` when cash and burn
 * are in different units and no rate is supplied (multi-currency is a named
 * residual), and `computed` only when a runway can be derived honestly.
 */
class VentureRunwayReader
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_NO_BURN = 'no_burn';

    public const STATUS_NEEDS_FX = 'needs_fx';

    public const STATUS_COMPUTED = 'computed';

    public function __construct(
        private readonly ReconciledCashEventStore $cash,
        private readonly VentureCostAttributionLedger $cost,
    ) {}

    /**
     * @param  float|null  $microUsdPerCashCent  explicit FX (micro-USD per 1 cash cent); null => needs_fx
     * @return array<string,mixed>
     */
    public function assess(string $ventureId, ?Carbon $now = null, ?float $microUsdPerCashCent = null): array
    {
        $now ??= Carbon::now();
        $windowStart = $now->copy()->subDays(30);

        $monthlyBurn = $this->cost->totalCostMicroUsd($ventureId, $windowStart, $now);
        if ($monthlyBurn === null) {
            return ['status' => self::STATUS_UNKNOWN, 'reason' => 'no_cost_data', 'monthly_burn_microusd' => null];
        }

        $settledCents = $this->cash->settledNetCentsForVenture($ventureId, Carbon::createFromTimestamp(0), $now);

        if ($monthlyBurn === 0) {
            return ['status' => self::STATUS_NO_BURN, 'monthly_burn_microusd' => 0, 'settled_net_cents' => $settledCents];
        }

        if ($microUsdPerCashCent === null) {
            return [
                'status' => self::STATUS_NEEDS_FX,
                'monthly_burn_microusd' => $monthlyBurn,
                'settled_net_cents' => $settledCents,
            ];
        }

        $cashMicroUsd = $settledCents * $microUsdPerCashCent;

        return [
            'status' => self::STATUS_COMPUTED,
            'monthly_burn_microusd' => $monthlyBurn,
            'settled_net_cents' => $settledCents,
            'runway_months' => round($cashMicroUsd / $monthlyBurn, 2),
        ];
    }
}
