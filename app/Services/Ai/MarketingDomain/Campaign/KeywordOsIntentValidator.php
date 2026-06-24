<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordOsIntentValidator — a prova HONESTA e NÃO-CIRCULAR de que a inteligência do OS é real: a intenção
 * que ele classifica (L3, só-texto) PREDIZ a venda real? Agrupa termos reais (Blackink) por tier de intenção
 * e mede a CVR real de cada tier. Se a CVR SOBE com o tier (T0→T4), o classificador genuinamente separa
 * curioso de comprador — a "assertividade provada" que a meta exige, medida contra dinheiro real, não proxy.
 *
 * Não-circular: o score de intenção vem do TEXTO; a CVR vem da VENDA. Se baterem, a inteligência é real.
 * Provider-free e determinístico (a CVR exige minClicks pra ser estatisticamente honesta).
 */
class KeywordOsIntentValidator
{
    private const TIERS = ['T0', 'T1', 'T2', 'T3', 'T4'];

    public function __construct(
        private readonly IntentLadderClassifier $intent = new IntentLadderClassifier,
    ) {}

    /**
     * Compara o lift intenção→CVR ENTRE nichos. Achado pétreo (ciclo 26): o tier-ouro é NICHE-DEPENDENT
     * — weight_loss converte no mecanismo coined (T4, lift 3.9x) mas niche de sintoma (tinnitus/blood_sugar/
     * prostate) converte no problem-aware (T1). Logo NÃO hard-codar T4>T1 nem T1>T4 no scorer; deixar o
     * FLYWHEEL (KeywordOutcomeCalibrator) aprender o valor real por-termo, que pega a dependência de nicho.
     *
     * @param  array<string,array<int,array{term:string,clicks:int|float,conversions:int|float}>>  $byNiche
     * @return array{per_niche:array<string,mixed>,niches:int,high_intent_wins:int,low_intent_wins:int,tier_value_is_niche_dependent:bool}
     */
    public function compareNiches(array $byNiche, int $minClicks = 30): array
    {
        $perNiche = [];
        $withLift = 0;
        $highWins = 0;
        foreach ($byNiche as $niche => $rows) {
            $res = $this->validate((array) $rows, $minClicks);
            $perNiche[$niche] = $res;
            if ($res['lift'] !== null) {
                $withLift++;
                if ($res['lift'] >= 1.0) {
                    $highWins++;
                }
            }
        }

        return [
            'per_niche' => $perNiche,
            'niches' => $withLift,
            'high_intent_wins' => $highWins,
            'low_intent_wins' => $withLift - $highWins,
            // uns com lift>1 e outros <1 ⇒ o melhor tier MUDA por nicho ⇒ não hard-codar, deixar o flywheel
            'tier_value_is_niche_dependent' => $highWins > 0 && $highWins < $withLift,
        ];
    }

    /**
     * @param  array<int,array{term:string,clicks:int|float,conversions:int|float}>  $rows
     * @return array{by_tier:array<string,array{clicks:int,conversions:int,cvr:float|null}>,high_cvr:float,low_cvr:float,lift:float|null,monotonic_pairs:int,total_pairs:int}
     */
    public function validate(array $rows, int $minClicks = 30): array
    {
        $agg = [];
        foreach ($rows as $r) {
            $term = mb_strtolower(trim((string) ($r['term'] ?? '')));
            if ($term === '' || str_contains($term, '{')) {
                continue;
            }
            $tier = (string) $this->intent->classify($term)['tier'];
            $agg[$tier]['clicks'] = ($agg[$tier]['clicks'] ?? 0) + (int) ($r['clicks'] ?? 0);
            $agg[$tier]['conv'] = ($agg[$tier]['conv'] ?? 0) + (int) ($r['conversions'] ?? 0);
        }

        $byTier = [];
        foreach (self::TIERS as $t) {
            $c = (int) ($agg[$t]['clicks'] ?? 0);
            $s = (int) ($agg[$t]['conv'] ?? 0);
            $byTier[$t] = ['clicks' => $c, 'conversions' => $s, 'cvr' => $c >= $minClicks ? round($s / $c, 5) : null];
        }

        // lift: CVR dos compradores (T3+T4) vs curiosos (T0+T1)
        $highC = $byTier['T3']['clicks'] + $byTier['T4']['clicks'];
        $highS = $byTier['T3']['conversions'] + $byTier['T4']['conversions'];
        $lowC = $byTier['T0']['clicks'] + $byTier['T1']['clicks'];
        $lowS = $byTier['T0']['conversions'] + $byTier['T1']['conversions'];
        $highCvr = $highC > 0 ? $highS / $highC : 0.0;
        $lowCvr = $lowC > 0 ? $lowS / $lowC : 0.0;

        // monotonicidade: de quantos pares de tiers consecutivos (com dado) a CVR sobe?
        $cvrs = array_values(array_filter(array_map(fn ($t) => $byTier[$t]['cvr'], self::TIERS), fn ($v) => $v !== null));
        $monotonic = 0;
        for ($i = 1; $i < count($cvrs); $i++) {
            if ($cvrs[$i] >= $cvrs[$i - 1]) {
                $monotonic++;
            }
        }

        return [
            'by_tier' => $byTier,
            'high_cvr' => round($highCvr, 5),
            'low_cvr' => round($lowCvr, 5),
            'lift' => $lowCvr > 0 ? round($highCvr / $lowCvr, 2) : null,
            'monotonic_pairs' => $monotonic,
            'total_pairs' => max(0, count($cvrs) - 1),
        ];
    }
}
