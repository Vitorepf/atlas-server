<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * BattleOutcomeAnalyzer — fecha o ciclo entre split-test (MultivariateBattlePlan) e learning. Quando
 * o operador roda o battle live e cada variante recebe seu CVR, este motor agrega POR EIXO: qual
 * angle ganhou em CVR médio, qual hook ganhou, qual awareness ganhou (controlado por nicho). Devolve
 * a recomendação de promoção (a variante a escalar) e os insights por axis — não só a página vencedora,
 * mas QUAL DECISÃO daquela página venceu. Provider-free; trabalha em memória sobre os outcomes
 * recebidos. É o que evita "ganhei por azar" e converte teste em aprendizado.
 */
class BattleOutcomeAnalyzer
{
    /**
     * @param  array<int,array{variant_id:string,axes:array{angle:string,hook:string,awareness:string},cvr:float,clicks?:int,conversions?:int}>  $outcomes
     * @return array{n:int,winner:?array{variant_id:string,cvr:float,axes:array<string,string>},axis_lift:array{angle:array<string,float>,hook:array<string,float>,awareness:array<string,float>},recommendation:array{promote:?string,axis_insights:array<int,string>}}
     */
    public function analyze(array $outcomes): array
    {
        $n = count($outcomes);
        if ($n === 0) {
            return ['n' => 0, 'winner' => null,
                'axis_lift' => ['angle' => [], 'hook' => [], 'awareness' => []],
                'recommendation' => ['promote' => null, 'axis_insights' => []]];
        }

        $byAxis = ['angle' => [], 'hook' => [], 'awareness' => []];
        foreach ($outcomes as $o) {
            foreach (['angle', 'hook', 'awareness'] as $axis) {
                $v = (string) ($o['axes'][$axis] ?? '');
                if ($v !== '') {
                    $byAxis[$axis][$v][] = (float) $o['cvr'];
                }
            }
        }

        $axisLift = ['angle' => [], 'hook' => [], 'awareness' => []];
        foreach ($byAxis as $axis => $groups) {
            foreach ($groups as $val => $cvrs) {
                $axisLift[$axis][$val] = round(array_sum($cvrs) / count($cvrs), 4);
            }
            arsort($axisLift[$axis]);
        }

        usort($outcomes, fn ($a, $b) => $b['cvr'] <=> $a['cvr']);
        $winner = $outcomes[0];

        $insights = [];
        foreach (['angle', 'hook', 'awareness'] as $axis) {
            if (count($axisLift[$axis]) < 2) {
                continue;
            }
            $vals = $axisLift[$axis];
            $top = array_key_first($vals);
            $bottom = array_key_last($vals);
            $delta = ($vals[$top] - $vals[$bottom]) / max(0.0001, $vals[$bottom]);
            if ($delta >= 0.20) {
                $insights[] = "{$axis} '{$top}' converte ".round($delta * 100)."% mais que '{$bottom}' — promover este eixo.";
            }
        }

        return [
            'n' => $n,
            'winner' => ['variant_id' => $winner['variant_id'], 'cvr' => $winner['cvr'], 'axes' => $winner['axes']],
            'axis_lift' => $axisLift,
            'pair_lift' => $this->pairLift($outcomes),
            'recommendation' => ['promote' => $winner['variant_id'], 'axis_insights' => $insights],
        ];
    }

    /**
     * Cross-axis pair detection: identifies combinations like (angle×hook) that DOMINATE across the
     * third axis — that's a real interaction pattern, not a single lucky variant. Returns top 3 pairs.
     *
     * @param  array<int,array{variant_id:string,axes:array{angle:string,hook:string,awareness:string},cvr:float}>  $outcomes
     * @return array<int,array{pair:string,avg_cvr:float,n:int}>
     */
    private function pairLift(array $outcomes): array
    {
        $pairs = [];
        foreach ([['angle', 'hook'], ['angle', 'awareness'], ['hook', 'awareness']] as [$a, $b]) {
            $bucket = [];
            foreach ($outcomes as $o) {
                $va = (string) ($o['axes'][$a] ?? '');
                $vb = (string) ($o['axes'][$b] ?? '');
                if ($va === '' || $vb === '') {
                    continue;
                }
                $bucket["{$a}={$va} × {$b}={$vb}"][] = (float) $o['cvr'];
            }
            foreach ($bucket as $key => $cvrs) {
                if (count($cvrs) < 2) {
                    continue;
                }
                $pairs[] = ['pair' => $key, 'avg_cvr' => round(array_sum($cvrs) / count($cvrs), 4), 'n' => count($cvrs)];
            }
        }
        usort($pairs, fn ($a, $b) => $b['avg_cvr'] <=> $a['avg_cvr']);

        return array_slice($pairs, 0, 3);
    }
}
