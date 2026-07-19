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
        $pool = []; // suite => arm => ['passed'=>int,'total'=>int]
        foreach ($rows as $row) {
            $suite = (string) $row['suite'];
            $arm = (string) $row['arm'];
            $pool[$suite][$arm]['passed'] = ((int) ($pool[$suite][$arm]['passed'] ?? 0)) + (int) $row['cases_passed'];
            $pool[$suite][$arm]['total'] = ((int) ($pool[$suite][$arm]['total'] ?? 0)) + (int) $row['cases_total'];
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
                    'suites' => [],
                ];
                if (is_array($baseline)) {
                    $capabilities[$capability]['baseline_passed'] += (int) $baseline['passed'];
                    $capabilities[$capability]['baseline_total'] += (int) $baseline['total'];
                }
                if (is_array($withAtlas)) {
                    $capabilities[$capability]['atlas_passed'] += (int) $withAtlas['passed'];
                    $capabilities[$capability]['atlas_total'] += (int) $withAtlas['total'];
                }
                $capabilities[$capability]['suites'][$suite] = true;
            }
        }

        $public = [];
        foreach ($capabilities as $row) {
            $bs = (int) $row['baseline_passed'];
            $bn = (int) $row['baseline_total'];
            $as = (int) $row['atlas_passed'];
            $an = (int) $row['atlas_total'];
            $suites = array_keys((array) $row['suites']);
            sort($suites);

            $baseline = $bn > 0 ? $this->arm($bs, $bn) : null;
            $withAtlas = $an > 0 ? $this->arm($as, $an) : null;

            // Delta com IC de Newcombe (Atlas − base). Significativo = o IC 95%
            // NÃO cruza zero. Só existe quando os dois braços têm ensaio.
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
}
