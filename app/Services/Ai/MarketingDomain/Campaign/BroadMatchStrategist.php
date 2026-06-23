<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * BroadMatchStrategist — the broad-match-strategist skill. Broad + Smart Bidding is the modern meta,
 * but ONLY with enough conversion signal and a real sale flowing back. Sets the match-type mix by the
 * account's daily conversion volume. Deterministic.
 */
class BroadMatchStrategist
{
    /**
     * @return array<string,mixed>
     */
    public function matchMix(int $dailyConversions, bool $conversionLoopComplete = true): array
    {
        // Without enough signal (or a broken postback) broad burns budget — stay tighter.
        [$broad, $phrase, $exact, $note] = match (true) {
            ! $conversionLoopComplete => [0, 40, 60, 'Loop de conversão incompleto — segurar em exact/phrase até a venda voltar pro Google.'],
            $dailyConversions >= 15 => [70, 20, 10, 'Volume suficiente — broad + Smart Bidding (o meta atual) com conversão de venda medida.'],
            $dailyConversions >= 5 => [40, 40, 20, 'Volume médio — mix balanceado; subir broad conforme a conversão sobe.'],
            default => [10, 40, 50, 'Cold start — exact/phrase domina até juntar dados; broad só de descoberta controlada.'],
        };

        return [
            'skill' => 'broad-match-strategist',
            'match_mix_pct' => ['broad' => $broad, 'phrase' => $phrase, 'exact' => $exact],
            'daily_conversions' => $dailyConversions,
            'note' => $note,
            'safety_rules' => [
                'broad exige conversão de VENDA real chegando no Google (senão compra lixo)',
                'usar exact-negatives das Alpha no Beta broad',
                'monitorar search terms (n-gram) e negativar o que não converte',
            ],
        ];
    }
}
