<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordParetoConcentrator — operacionaliza a lei `marshall-8020-concentration`: acha os VITAL FEW, o menor
 * conjunto de keywords que captura X% (default 80%) da RECEITA projetada. É a disciplina 80/20 de Perry
 * Marshall como ferramenta: "estas N keywords = 80% da receita — foco obsessivo aqui; o resto é a maioria
 * trivial". A validação held-out deste OS mediu concentração ~97/3, então o corte costuma ser brutal.
 *
 * Determinístico, provider-free. Ordena por receita desc (o maior contribuinte primeiro) e acumula até o alvo.
 */
class KeywordParetoConcentrator
{
    /**
     * @param  array<int,array<string,mixed>>  $ranked  linhas do revenue_ranking (cada uma com expected_revenue)
     * @param  float  $cut  fração da receita a capturar (0..1, default 0.80)
     * @return array{vital_few:array<int,array<string,mixed>>,vital_count:int,total_count:int,revenue_captured:float,revenue_total:float,cut:float,concentration:string}
     */
    public function concentrate(array $ranked, float $cut = 0.80): array
    {
        $cut = max(0.01, min(1.0, $cut));
        $rows = array_values(array_filter(
            $ranked,
            fn ($r) => is_array($r) && (float) ($r['expected_revenue'] ?? 0) > 0,
        ));
        // Pareto de RECEITA: o maior contribuinte primeiro (não assume a ordem de entrada, que é por lucro)
        usort($rows, fn ($a, $b) => (float) $b['expected_revenue'] <=> (float) $a['expected_revenue']);

        $total = array_sum(array_map(fn ($r) => (float) $r['expected_revenue'], $rows));
        $tc = count($rows);
        if ($total <= 0) {
            return ['vital_few' => [], 'vital_count' => 0, 'total_count' => $tc, 'revenue_captured' => 0.0, 'revenue_total' => 0.0, 'cut' => $cut, 'concentration' => 'sem receita projetada'];
        }

        $target = $total * $cut;
        $acc = 0.0;
        $vital = [];
        foreach ($rows as $r) {
            $vital[] = $r;
            $acc += (float) $r['expected_revenue'];
            if ($acc >= $target) {
                break;
            }
        }
        $vc = count($vital);

        return [
            'vital_few' => $vital,
            'vital_count' => $vc,
            'total_count' => $tc,
            'revenue_captured' => round($acc, 2),
            'revenue_total' => round($total, 2),
            'cut' => $cut,
            'concentration' => sprintf(
                '%d de %d keywords (%.1f%%) capturam %.0f%% da receita — foco obsessivo nos vital few, corte a cauda',
                $vc, $tc, $tc > 0 ? $vc / $tc * 100 : 0, $cut * 100,
            ),
        ];
    }
}
