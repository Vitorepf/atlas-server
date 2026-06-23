<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * AffiliateNetworksReference — deterministic reference table for the offer-scout skill: per-network
 * payout model, typical rate, payment cadence, default refund baseline, postback speed and tracking
 * quality. Informational (never a gate); used to project EPC and cash-flow when scoring a known offer.
 */
final class AffiliateNetworksReference
{
    /** @var array<string,array<string,mixed>> */
    public const NETWORKS = [
        'clickbank' => [
            'vertical_focus' => 'health/nutra/finance/self-help',
            'payout_model' => 'RevShare/CPA',
            'typical_rate_pct' => 75,
            'payment_cadence' => 'weekly→3x/week por faixa de receita',
            'default_refund_rate_pct' => 12,
            'postback_speed_hours' => 1,
            'cookie_days' => 60,
            'tracking_quality' => 'high (S2S postback vetado)',
        ],
        'buygoods' => [
            'vertical_focus' => 'health/nutra/supplements',
            'payout_model' => 'CPA/RevShare',
            'typical_rate_pct' => 60,
            'payment_cadence' => 'weekly→3x/week por faixa',
            'default_refund_rate_pct' => 10,
            'postback_speed_hours' => 2,
            'cookie_days' => 30,
            'tracking_quality' => 'high',
        ],
        'maxweb' => [
            'vertical_focus' => 'health/fitness/beauty (VSL clássico)',
            'payout_model' => 'CPA only',
            'typical_rate_pct' => 0,
            'payment_cadence' => 'weekly→3x/week ($100 ACH min)',
            'default_refund_rate_pct' => 8,
            'postback_speed_hours' => 2,
            'cookie_days' => 30,
            'tracking_quality' => 'high',
        ],
        'digistore24' => [
            'vertical_focus' => 'health/finance/education',
            'payout_model' => 'RevShare/CPA',
            'typical_rate_pct' => 60,
            'payment_cadence' => 'weekly/biweekly',
            'default_refund_rate_pct' => 11,
            'postback_speed_hours' => 2,
            'cookie_days' => 180,
            'tracking_quality' => 'high',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public static function get(?string $network): array
    {
        $key = strtolower(trim((string) $network));

        return self::NETWORKS[$key] ?? [
            'vertical_focus' => 'desconhecido',
            'payout_model' => 'unknown',
            'typical_rate_pct' => 0,
            'payment_cadence' => 'confirmar com a rede',
            'default_refund_rate_pct' => 12,
            'postback_speed_hours' => 24,
            'cookie_days' => 30,
            'tracking_quality' => 'unknown',
        ];
    }

    public static function defaultRefundRate(?string $network): float
    {
        return (float) self::get($network)['default_refund_rate_pct'] / 100;
    }
}
