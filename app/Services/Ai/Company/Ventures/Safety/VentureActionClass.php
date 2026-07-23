<?php

namespace App\Services\Ai\Company\Ventures\Safety;

/**
 * K5 — the closed taxonomy of external venture actions. A frozen code enum (not
 * a DB row), so a fabricated class can never smuggle past the dispatch boundary.
 * Reversibility/spend metadata is the un-gameable structural floor.
 */
enum VentureActionClass: string
{
    case MARKETING_POST = 'marketing_post';
    case EMAIL = 'email';
    case PRICE_CHANGE = 'price_change';
    case AD_SPEND = 'ad_spend';
    case CHARGE = 'charge';
    case CONTRACT = 'contract';
    case OUTREACH = 'outreach';
    case REFUND = 'refund';

    /** full | partial | none. `none` = irreversible (always needs a mandate). */
    public function reversibility(): string
    {
        return match ($this) {
            self::PRICE_CHANGE, self::MARKETING_POST => 'partial',
            self::EMAIL, self::OUTREACH, self::CHARGE, self::CONTRACT, self::AD_SPEND, self::REFUND => 'none',
        };
    }

    public function isIrreversible(): bool
    {
        return $this->reversibility() === 'none';
    }

    /** Touches money outflow under a windowed cap (K3). */
    public function isSpend(): bool
    {
        return match ($this) {
            self::AD_SPEND, self::REFUND => true,
            default => false,
        };
    }
}
