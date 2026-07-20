<?php

namespace App\Services\Ai\Arena;

use App\Services\Ai\Rivals\Core\StatisticalPolicy;

final class ArenaCapabilityProfileService
{
    public function __construct(private readonly ArenaMeasurementStore $store = new ArenaMeasurementStore) {}

    /** @return array<string, mixed> */
    public function profile(?string $engine = null): array
    {
        $rows = array_values(array_filter(
            $this->store->measurements(),
            fn (array $row): bool => $engine === null || $engine === '' || $row['engine'] === $engine
        ));
        $labels = (array) config('atlas_arena.capability_labels_pt', []);
        $minCases = max(1, (int) config('atlas_arena.min_cases_for_confidence', 10));

        // VOLUME: agrupa por (suite, braço) somando os casos de TODAS as rodadas.
        // O `latestBySuiteArm` antigo usava só a última rodada e jogava fora a
        // repetição — exatamente o que dá confiança estatística. Aqui cada caso
        // medido (em qualquer rodada) é um ensaio de Bernoulli que entra no N.
        // ponytail: ensaios repetidos do MESMO caso são correlacionados, então o
        // IC de Wilson fica um tico otimista; troca por hierarchicalBootstrap
        // quando o store expuser sucessos por caso (hoje só expõe passed/total).
        $pool = []; // suite => arm => ['passed','total', (contínuo:) 'score_sum','score_sumsq','score_n']
        foreach ($rows as $row) {
            $suite = (string) $row['suite'];
            $arm = (string) $row['arm'];
            $passed = (int) $row['cases_passed'];
            $total = (int) $row['cases_total'];
            $pool[$suite][$arm]['passed'] = ((int) ($pool[$suite][$arm]['passed'] ?? 0)) + $passed;
            $pool[$suite][$arm]['total'] = ((int) ($pool[$suite][$arm]['total'] ?? 0)) + $total;
            // O acumulador contínuo é preenchido SEMPRE, inclusive por suíte binária:
            // um caso pass/fail é um score de Bernoulli ∈ {0,1}, então sum = acertos e
            // sumsq = acertos (1²=1, 0²=0). Sem isto, capacidade que MISTURA suíte
            // contínua com binária caía no ramo contínuo e descartava EM SILÊNCIO os
            // casos binários — code_generation (deveval contínuo + evalplus/bigcodebench/
            // classeval binários) reportava with_atlas_cases=0 com 30 casos Atlas medidos
            // no store. Zero falso disfarçado de "não medido" é exatamente o que a lei
            // proíbe; agora nenhuma evidência some.
            if (($row['measurement_type'] ?? 'binary') === 'continuous') {
                $pool[$suite][$arm]['continuous'] = true;
                $pool[$suite][$arm]['score_sum'] = ((float) ($pool[$suite][$arm]['score_sum'] ?? 0.0)) + (float) ($row['score_sum'] ?? 0.0);
                $pool[$suite][$arm]['score_sumsq'] = ((float) ($pool[$suite][$arm]['score_sumsq'] ?? 0.0)) + (float) ($row['score_sumsq'] ?? 0.0);
                $pool[$suite][$arm]['score_n'] = ((int) ($pool[$suite][$arm]['score_n'] ?? 0)) + (int) ($row['score_n'] ?? 0);
            } else {
                $pool[$suite][$arm]['binary'] = true;
                $pool[$suite][$arm]['score_sum'] = ((float) ($pool[$suite][$arm]['score_sum'] ?? 0.0)) + (float) $passed;
                $pool[$suite][$arm]['score_sumsq'] = ((float) ($pool[$suite][$arm]['score_sumsq'] ?? 0.0)) + (float) $passed;
                $pool[$suite][$arm]['score_n'] = ((int) ($pool[$suite][$arm]['score_n'] ?? 0)) + $total;
            }
        }

        /** @var array<string, array<string, mixed>> $capabilities */
        $capabilities = [];
        foreach ((array) config('atlas_arena.capability_map', []) as $suite => $entries) {
            if (! is_string($suite) || ! isset($pool[$suite])) {
                continue;
            }
            $baseline = $pool[$suite]['baseline'] ?? null;
            $withAtlas = $pool[$suite]['with_atlas'] ?? null;
            foreach ((array) $entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $capability = (string) ($entry['capability'] ?? '');
                if ($capability === '') {
                    continue;
                }
                // O peso do capability_map é editorial (compor o score público);
                // para a CONFIANÇA o que vale é o ensaio real, então a capacidade
                // agrega os casos brutos das suites que a informam. Uma suite pode
                // informar duas capacidades (lcb → code_editing + reasoning): são
                // as MESMAS trials como evidência de cada uma, sem dupla contagem
                // dentro de uma capacidade.
                $capabilities[$capability] ??= [
                    'capability' => $capability,
                    'label_pt' => (string) ($labels[$capability] ?? $capability),
                    'baseline_passed' => 0,
                    'baseline_total' => 0,
                    'atlas_passed' => 0,
                    'atlas_total' => 0,
                    'continuous' => false,
                    'binary' => false,
                    'baseline_sum' => 0.0,
                    'baseline_sumsq' => 0.0,
                    'baseline_scoreN' => 0,
                    'atlas_sum' => 0.0,
                    'atlas_sumsq' => 0.0,
                    'atlas_scoreN' => 0,
                    'suites' => [],
                ];
                if (is_array($baseline)) {
                    $capabilities[$capability]['baseline_passed'] += (int) $baseline['passed'];
                    $capabilities[$capability]['baseline_total'] += (int) $baseline['total'];
                    $capabilities[$capability]['continuous'] = ($capabilities[$capability]['continuous'] || ($baseline['continuous'] ?? false));
                    $capabilities[$capability]['binary'] = ($capabilities[$capability]['binary'] || ($baseline['binary'] ?? false));
                    $capabilities[$capability]['baseline_sum'] += (float) ($baseline['score_sum'] ?? 0.0);
                    $capabilities[$capability]['baseline_sumsq'] += (float) ($baseline['score_sumsq'] ?? 0.0);
                    $capabilities[$capability]['baseline_scoreN'] += (int) ($baseline['score_n'] ?? 0);
                }
                if (is_array($withAtlas)) {
                    $capabilities[$capability]['atlas_passed'] += (int) $withAtlas['passed'];
                    $capabilities[$capability]['atlas_total'] += (int) $withAtlas['total'];
                    $capabilities[$capability]['continuous'] = ($capabilities[$capability]['continuous'] || ($withAtlas['continuous'] ?? false));
                    $capabilities[$capability]['binary'] = ($capabilities[$capability]['binary'] || ($withAtlas['binary'] ?? false));
                    $capabilities[$capability]['atlas_sum'] += (float) ($withAtlas['score_sum'] ?? 0.0);
                    $capabilities[$capability]['atlas_sumsq'] += (float) ($withAtlas['score_sumsq'] ?? 0.0);
                    $capabilities[$capability]['atlas_scoreN'] += (int) ($withAtlas['score_n'] ?? 0);
                }
                $capabilities[$capability]['suites'][$suite] = true;
            }
        }

        $public = [];
        foreach ($capabilities as $row) {
            $suites = array_keys((array) $row['suites']);
            sort($suites);
            // Só o ramo binário puro usa Wilson/Newcombe (é o certo p/ proporção).
            // Misto (contínuo + binário na mesma capacidade) vai pro ramo contínuo,
            // que agora carrega TODAS as evidências — e é rotulado `mixed` pra que o
            // app não venda média de rougeL como se fosse pass@1.
            $continuous = (bool) $row['continuous'];

            if ($continuous) {
                // Nota = MÉDIA do score contínuo (rougeL etc.), com IC normal.
                // N = casos com score; nunca a taxa binária de "completou".
                $bn = (int) $row['baseline_scoreN'];
                $an = (int) $row['atlas_scoreN'];
                $baseline = $bn > 0 ? $this->continuousArm((float) $row['baseline_sum'], (float) $row['baseline_sumsq'], $bn) : null;
                $withAtlas = $an > 0 ? $this->continuousArm((float) $row['atlas_sum'], (float) $row['atlas_sumsq'], $an) : null;
                $delta = ($baseline !== null && $withAtlas !== null)
                    ? $this->continuousDelta($baseline, $withAtlas)
                    : null;
                $measureLabel = ((bool) $row['binary']) ? 'mixed' : 'continuous';
            } else {
                $bs = (int) $row['baseline_passed'];
                $bn = (int) $row['baseline_total'];
                $as = (int) $row['atlas_passed'];
                $an = (int) $row['atlas_total'];
                $baseline = $bn > 0 ? $this->arm($bs, $bn) : null;
                $withAtlas = $an > 0 ? $this->arm($as, $an) : null;
                // Delta com IC de Newcombe (Atlas − base). Significativo = IC não cruza 0.
                $delta = null;
                if ($bn > 0 && $an > 0) {
                    $diff = StatisticalPolicy::newcombeDiff($as, $an, $bs, $bn);
                    $delta = [
                        'value' => $diff['diff'],
                        'ci_low' => $diff['ci_low'],
                        'ci_high' => $diff['ci_high'],
                        'significant' => $diff['ci_low'] > 0.0 || $diff['ci_high'] < 0.0,
                    ];
                }
                $measureLabel = 'binary';
            }

            // Confiança da COMPARAÇÃO: sem os dois braços não há o que comparar
            // (unmeasured); com poucos casos o número existe mas não é confiável
            // (low); só measured quando os dois braços passam do piso.
            $confidence = match (true) {
                $bn === 0 || $an === 0 => 'unmeasured',
                min($bn, $an) < $minCases => 'low',
                default => 'measured',
            };

            $public[] = [
                'capability' => $row['capability'],
                'label_pt' => $row['label_pt'],
                'score' => $baseline['score'] ?? null,
                'with_atlas' => $withAtlas['score'] ?? null,
                'baseline_ci' => $baseline === null ? null : [$baseline['ci_low'], $baseline['ci_high']],
                'with_atlas_ci' => $withAtlas === null ? null : [$withAtlas['ci_low'], $withAtlas['ci_high']],
                'baseline_cases' => $bn,
                'with_atlas_cases' => $an,
                'delta' => $delta,
                'confidence' => $confidence,
                'measurement_type' => $measureLabel,
                'suites_contributing' => $suites,
                'cases_total' => max($bn, $an),
                'min_cases_for_confidence' => $minCases,
            ];
        }
        usort($public, static fn (array $a, array $b): int => strcmp($a['capability'], $b['capability']));

        return [
            'schema_version' => 'atlas.arena.capabilities.v2',
            'mapping_version' => 'arena.capability_map.v1',
            'engine' => $engine,
            'capabilities' => $public,
        ];
    }

    /**
     * Ponto + IC 95% de Wilson de um braço a partir dos casos agregados.
     *
     * @return array{score: float, ci_low: float, ci_high: float}
     */
    private function arm(int $passed, int $total): array
    {
        $ci = StatisticalPolicy::wilson($passed, $total);

        return [
            'score' => round($passed / $total, 4),
            'ci_low' => $ci['low'],
            'ci_high' => $ci['high'],
        ];
    }

    /**
     * Média + IC 95% normal de um braço com métrica CONTÍNUA (score ∈ [0,1]).
     * Wilson é pra proporção; aqui a nota é a média de um score real (rougeL), então
     * o IC é média ± z·erro-padrão (aprox. normal), preso em [0,1]. `se` fica p/ o delta.
     *
     * @return array{score: float, ci_low: float, ci_high: float, mean: float, se: float}
     */
    private function continuousArm(float $sum, float $sumsq, int $n): array
    {
        $z = 1.959963984540054;
        $mean = $sum / $n;
        $variance = max(0.0, ($sumsq / $n) - ($mean * $mean));
        $se = sqrt($variance / $n);

        return [
            'score' => round($mean, 4),
            'ci_low' => round(max(0.0, $mean - $z * $se), 6),
            'ci_high' => round(min(1.0, $mean + $z * $se), 6),
            'mean' => $mean,
            'se' => $se,
        ];
    }

    /**
     * Delta de médias contínuas (Atlas − base) com IC normal de duas amostras.
     * Significativo = o IC 95% não cruza zero.
     *
     * @param  array{mean: float, se: float}  $baseline
     * @param  array{mean: float, se: float}  $withAtlas
     * @return array{value: float, ci_low: float, ci_high: float, significant: bool}
     */
    private function continuousDelta(array $baseline, array $withAtlas): array
    {
        $z = 1.959963984540054;
        $diff = $withAtlas['mean'] - $baseline['mean'];
        $se = sqrt(($withAtlas['se'] ** 2) + ($baseline['se'] ** 2));
        $low = round($diff - $z * $se, 6);
        $high = round($diff + $z * $se, 6);

        return [
            'value' => round($diff, 6),
            'ci_low' => $low,
            'ci_high' => $high,
            'significant' => $low > 0.0 || $high < 0.0,
        ];
    }
}
