<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordScalingDiagnostic — a skill de ESCALA ("escalar MILHÕES"): decide AUMENTAR/segurar/recuar budget
 * por SINAL, não por % fixo (a regra dos 15%/semana morreu em 2026; Smart Bidding adapta mais rápido).
 *
 * Lei `scaling-readiness-2026`: pronto pra mais budget quando bate o ROAS-alvo por ≥14 dias + PERDE impression
 * share por BUDGET (headroom) + CVR estável/subindo + CPC não inflando. O mercado tem TETO no teu nível de
 * eficiência; gastar além = tráfego pior. Pra VOLUME no tROAS sem headroom de budget, REDUZ o target ROAS.
 *
 * A LÓGICA é determinística e provável agora; os SINAIS (IS-lost-to-budget, ROAS-janela…) chegam live —
 * até lá distingo prior de prova, nunca finjo (calibração DORMANT até live).
 */
class KeywordScalingDiagnostic
{
    private const MIN_WINDOW_DAYS = 14;

    /**
     * @param  array{roas?:float,target_roas?:float,days_in_window?:int,impr_share_lost_to_budget?:float,cvr_trend?:string,cpc_inflation?:float}  $signals
     * @return array{action:string,budget_multiplier:float,troas_action:string,reason:string}
     */
    public function diagnose(array $signals): array
    {
        $roas = (float) ($signals['roas'] ?? 0);
        $target = (float) ($signals['target_roas'] ?? 0);
        $days = (int) ($signals['days_in_window'] ?? 0);
        $isLost = (float) ($signals['impr_share_lost_to_budget'] ?? 0); // 0..1
        $cvrTrend = (string) ($signals['cvr_trend'] ?? 'flat');         // rising|flat|falling
        $cpcInfl = (float) ($signals['cpc_inflation'] ?? 0);            // inflação de CPC além do ganho de CVR

        // (0) janela curta → sem sinal confiável (regra 2026: ≥14 dias)
        if ($days < self::MIN_WINDOW_DAYS) {
            return $this->r('hold', 1.0, 'none', 'janela < '.self::MIN_WINDOW_DAYS.' dias — sem sinal confiável pra escalar');
        }

        // (1) ROAS abaixo do alvo → recuar, NUNCA escalar pra perder mais
        if ($target > 0 && $roas < $target) {
            return $this->r('pull_back', 0.8, 'none', 'ROAS abaixo do alvo — recuar budget, não escalar');
        }

        $cvrOk = $cvrTrend !== 'falling';
        $cpcOk = $cpcInfl <= 0.15; // CPC inflando até 15% além do ganho de CVR ainda é saudável

        // (3) TETO: quase nada perdido por budget → mercado saturado no nível atual; pra volume, baixar tROAS
        if ($isLost < 0.05) {
            return $this->r('hold', 1.0, 'reduce_troas', 'teto do mercado no nível de eficiência — pra mais volume REDUZA o target ROAS gradual (entra em mais leilões), não jogue budget');
        }

        // (2) headroom ALTO + saudável → escalar agressivo
        if ($isLost >= 0.20 && $cvrOk && $cpcOk) {
            return $this->r('scale_aggressive', 1.5, 'none', 'ROAS≥alvo + perde '.round($isLost * 100).'% de IS por budget (headroom alto) + CVR/CPC saudáveis');
        }

        // headroom moderado, ou alto mas com CVR/CPC sob pressão → escalar moderado
        if ($isLost >= 0.05 && $cvrOk) {
            return $this->r('scale_moderate', 1.2, 'none', 'ROAS≥alvo + headroom moderado/sob-pressão — escalar com cautela');
        }

        // CVR caindo apesar de headroom → não escalar (qualidade deteriorando)
        return $this->r('hold', 1.0, 'none', 'headroom existe mas CVR caindo — segurar até estabilizar (não comprar tráfego pior)');
    }

    private function r(string $action, float $mult, string $troas, string $reason): array
    {
        return ['action' => $action, 'budget_multiplier' => $mult, 'troas_action' => $troas, 'reason' => $reason];
    }
}
