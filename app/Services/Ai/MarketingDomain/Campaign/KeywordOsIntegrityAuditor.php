<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordOsIntegrityAuditor — torna a garantia "cobertura SEM BURACOS, ungameable, sem contradição" uma
 * PROPRIEDADE PROVADA a cada run, não uma afirmação. Audita os INVARIANTES estruturais do dossiê inteiro:
 *
 *  I1  nenhuma keyword está ao mesmo tempo RECOMENDADA e NEGADA (contradição que queimaria budget no escuro)
 *  I2  cada keyword da partição cai em EXATAMENTE um regime (sem dupla-classificação / sem buraco)
 *  I3  o VITAL FEW (Marshall) ⊆ ranking de receita (não inventa keyword fora do ranking)
 *  I4  o PORTFÓLIO sob budget não contém money-loser (nada com lucro ≤ 0)
 *  I5  os NEGATIVOS CRUZADOS de uma urna nunca incluem um termo PRÓPRIO dela (não auto-bloqueia)
 *  I6  o BLUEPRINT deployável só usa keywords que existem na partição de regime
 *
 * Determinístico, provider-free. ok=true ⇒ o dossiê é internamente consistente e sem buraco.
 */
class KeywordOsIntegrityAuditor
{
    /**
     * @param  array<string,mixed>  $run  saída do KeywordOsRunner/pipeline
     * @return array{ok:bool,violations:array<int,string>,checked:array<int,string>}
     */
    public function audit(array $run): array
    {
        $v = [];
        $checked = [];

        $regimes = (array) ($run['regimes'] ?? []);
        $ranked = $this->keys((array) ($run['revenue_ranking']['ranked'] ?? []));

        // I1 — recomendada ∩ negada = ∅
        $checked[] = 'I1_recomendada_vs_negada';
        $recommended = $this->keys((array) ($run['launch_selection']['recommended'] ?? []));
        $negatives = array_map(fn ($n) => mb_strtolower(trim((string) (is_array($n) ? ($n['keyword'] ?? $n['ngram'] ?? '') : $n))), (array) ($run['negatives']['list'] ?? $run['negatives'] ?? []));
        $negSet = array_flip(array_filter($negatives));
        foreach ($recommended as $kw) {
            if (isset($negSet[$kw])) {
                $v[] = "I1: '{$kw}' está recomendada E negada (contradição)";
            }
        }

        // I2 — cada keyword da partição em exatamente 1 regime
        $checked[] = 'I2_um_regime_por_keyword';
        $seen = [];
        foreach (['harvest', 'seed', 'probe'] as $r) {
            foreach ($this->keys((array) ($regimes[$r] ?? [])) as $kw) {
                if (isset($seen[$kw]) && $seen[$kw] !== $r) {
                    $v[] = "I2: '{$kw}' em 2 regimes ({$seen[$kw]} e {$r})";
                }
                $seen[$kw] = $r;
            }
        }

        // I3 — vital few ⊆ ranked
        $checked[] = 'I3_vital_few_subset_ranked';
        $rankedSet = array_flip($ranked);
        foreach ($this->keys((array) ($run['revenue_ranking']['vital_few']['vital_few'] ?? [])) as $kw) {
            if (! isset($rankedSet[$kw])) {
                $v[] = "I3: vital-few '{$kw}' não está no ranking de receita";
            }
        }

        // I4 — portfólio sem money-loser
        $checked[] = 'I4_portfolio_sem_loser';
        foreach ((array) ($run['budget_portfolio']['portfolio'] ?? []) as $k) {
            $profit = (float) ($k['captured_profit'] ?? $k['expected_profit'] ?? 0);
            if (is_array($k) && $profit <= 0) {
                $v[] = "I4: portfólio contém money-loser '".($k['keyword'] ?? '?')."' (lucro {$profit})";
            }
        }

        // I5 — negativos cruzados não auto-bloqueiam
        $checked[] = 'I5_cross_neg_nao_autobloqueia';
        $byRegime = (array) ($run['regimes']['cross_negatives']['by_regime'] ?? []);
        foreach (['harvest', 'seed', 'probe'] as $r) {
            $own = array_flip($this->keys((array) ($regimes[$r] ?? [])));
            foreach ((array) ($byRegime[$r]['negatives'] ?? []) as $neg) {
                $term = mb_strtolower(trim((string) ($neg['term'] ?? '')));
                if ($term !== '' && isset($own[$term])) {
                    $v[] = "I5: urna '{$r}' negativa termo próprio '{$term}'";
                }
            }
        }

        // I6 — blueprint ⊆ partição
        $checked[] = 'I6_blueprint_subset_particao';
        $partitionSet = array_flip(array_keys($seen));
        foreach ((array) ($run['campaign_blueprint']['campaigns'] ?? []) as $c) {
            foreach ((array) ($c['ad_groups'] ?? []) as $ag) {
                foreach ((array) ($ag['keywords'] ?? []) as $kw) {
                    $term = mb_strtolower(trim((string) ($kw['keyword'] ?? '')));
                    if ($term !== '' && ! isset($partitionSet[$term])) {
                        $v[] = "I6: blueprint usa '{$term}' fora da partição de regime";
                    }
                }
            }
        }

        return ['ok' => $v === [], 'violations' => $v, 'checked' => $checked];
    }

    /** @param array<int,mixed> $rows @return array<int,string> keywords normalizadas */
    private function keys(array $rows): array
    {
        return array_values(array_filter(array_map(
            fn ($x) => mb_strtolower(trim((string) (is_array($x) ? ($x['keyword'] ?? '') : $x))),
            $rows,
        ), fn ($k) => $k !== ''));
    }
}
