<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingWinningPattern;

/**
 * SmartBiddingReadinessDiagnostic — answers "can I move to tROAS/tCPA yet, and if not, how many more
 * conversions in how many days?" grounded in the real Nivor volume. BidStrategyDecider hard-codes the
 * thresholds (15/30); this says WHERE the account actually is against them. Deterministic.
 */
class SmartBiddingReadinessDiagnostic
{
    public const CONV_FOR_TROAS = 15;   // per 30 days

    public const CONV_FOR_TCPA = 30;    // per 30 days

    /**
     * @param  array<string,mixed>  $opts  daily_conversions, conversions_last_30d, conversion_loop_complete
     * @return array<string,mixed>
     */
    public function assess(AiMarketingWinningPattern $pattern, array $opts = []): array
    {
        $dailyConv = max(0.0, (float) ($opts['daily_conversions'] ?? 0));
        $conv30 = (int) ($opts['conversions_last_30d'] ?? 0);
        $loopOk = (bool) ($opts['conversion_loop_complete'] ?? true);

        $daysTo = fn (int $target): ?int => $dailyConv > 0 && $conv30 < $target
            ? (int) ceil(($target - $conv30) / $dailyConv)
            : ($conv30 >= $target ? 0 : null);

        $troasReady = $conv30 >= self::CONV_FOR_TROAS && $loopOk;
        $tcpaReady = $conv30 >= self::CONV_FOR_TCPA && $loopOk;

        return [
            'skill' => 'smart-bidding-readiness',
            'niche' => $pattern->niche,
            'real_cvr' => (float) $pattern->real_cvr,
            'conversions_last_30d' => $conv30,
            'daily_conversions' => $dailyConv,
            'data_quality_block' => ! $loopOk,
            'per_strategy' => [
                'maximize_conversions' => ['ready' => true, 'note' => 'cold start — sempre disponível pra juntar dados'],
                'target_roas' => ['ready' => $troasReady, 'days_to_ready' => $daysTo(self::CONV_FOR_TROAS), 'threshold' => self::CONV_FOR_TROAS],
                'target_cpa' => ['ready' => $tcpaReady, 'days_to_ready' => $daysTo(self::CONV_FOR_TCPA), 'threshold' => self::CONV_FOR_TCPA],
            ],
            'migration_roadmap' => $this->roadmap($troasReady, $tcpaReady, $loopOk),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function roadmap(bool $troas, bool $tcpa, bool $loopOk): array
    {
        if (! $loopOk) {
            return ['Fechar o loop de conversão (venda real → Google) ANTES de migrar — senão o Smart Bidding aprende com dado falso.'];
        }
        $steps = ['1. Rodar Maximize Conversions até juntar volume.'];
        $steps[] = $troas ? '2. ✅ Migrar pra Target ROAS (≥15 conv/30d atingido).' : '2. Aguardar ≥15 conv/30d → Target ROAS.';
        $steps[] = $tcpa ? '3. ✅ Estável pra Target CPA (≥30 conv/30d).' : '3. Aguardar ≥30 conv/30d → Target CPA (custo fixo no teto).';

        return $steps;
    }
}
