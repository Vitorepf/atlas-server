<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * MarketSpyHarvester — escala o WinningPatternScout. O scout lê 1 página; o harvester recebe N
 * páginas vencedoras e devolve a INTERSECÇÃO INTELIGENTE: padrões que repetem em K+ ofertas (sinal
 * de tendência do mercado, não acaso de uma página), candidatos novos repetidos (alvo da próxima
 * deepening cycle), e o ranking de signal density agregada por library. Provider-free. Aprendi
 * com 1 página → aprendo com a safra atual do mercado.
 */
class MarketSpyHarvester
{
    public function __construct(private readonly WinningPatternScout $scout = new WinningPatternScout) {}

    /**
     * @param  array<int,string>  $pages  HTMLs (ou texto) das páginas vencedoras
     * @param  int  $repeatThreshold  K — padrão precisa aparecer em K+ páginas pra contar como tendência
     * @return array{n_pages:int,trend_patterns:array<int,array{pattern:string,seen_in:int,share:float}>,trend_candidates:array<int,array{snippet:string,seen_in:int,why:string}>,library_density:array<string,float>,avg_overall_score:float,avg_hollowness:float}
     */
    public function harvest(array $pages, int $repeatThreshold = 0): array
    {
        $n = count($pages);
        if ($n === 0) {
            return ['n_pages' => 0, 'trend_patterns' => [], 'trend_candidates' => [], 'library_density' => [], 'avg_overall_score' => 0.0, 'avg_hollowness' => 0.0];
        }

        // Auto-threshold: half the pages, but at least 2 (single overlap doesn't count as trend)
        if ($repeatThreshold <= 0) {
            $repeatThreshold = (int) max(2, ceil($n / 2));
        }

        $patternCounts = [];          // 'lib:key' => count
        $candidateCounts = [];        // normalized why-key => ['snippet'=>..., 'count'=>n]
        $densitySum = [];             // lib => sum of densities
        $scoreSum = 0.0;
        $hollowSum = 0.0;

        foreach ($pages as $html) {
            $r = $this->scout->scout((string) $html);

            foreach ($r['fingerprint'] as $p) {
                $patternCounts[$p] = ($patternCounts[$p] ?? 0) + 1;
            }
            foreach ($r['candidates'] as $c) {
                $key = $this->candidateKey($c['why']);
                $candidateCounts[$key] = $candidateCounts[$key] ?? ['snippet' => $c['snippet'], 'why' => $c['why'], 'count' => 0];
                $candidateCounts[$key]['count']++;
            }
            foreach ($r['signal_density'] as $lib => $v) {
                $densitySum[$lib] = ($densitySum[$lib] ?? 0.0) + (float) $v;
            }
            $scoreSum += (float) ($r['audit']['overall_score'] ?? 0);
            $hollowSum += (float) ($r['hollowness']['hollowness'] ?? 0);
        }

        // Trend patterns: only those crossing the repeat threshold
        $trendPatterns = [];
        foreach ($patternCounts as $p => $c) {
            if ($c >= $repeatThreshold) {
                $trendPatterns[] = ['pattern' => $p, 'seen_in' => $c, 'share' => round($c / $n, 2)];
            }
        }
        usort($trendPatterns, static fn ($a, $b) => $b['seen_in'] <=> $a['seen_in']);

        // Trend candidates: things to encode in next deepening
        $trendCandidates = [];
        foreach ($candidateCounts as $c) {
            if ($c['count'] >= $repeatThreshold) {
                $trendCandidates[] = ['snippet' => mb_strimwidth($c['snippet'], 0, 80, '…'), 'seen_in' => $c['count'], 'why' => $c['why']];
            }
        }
        usort($trendCandidates, static fn ($a, $b) => $b['seen_in'] <=> $a['seen_in']);

        $libDensity = [];
        foreach ($densitySum as $lib => $sum) {
            $libDensity[$lib] = round($sum / $n, 2);
        }
        arsort($libDensity);

        return [
            'n_pages' => $n,
            'trend_patterns' => array_slice($trendPatterns, 0, 25),
            'trend_candidates' => array_slice($trendCandidates, 0, 10),
            'library_density' => $libDensity,
            'avg_overall_score' => round($scoreSum / $n, 1),
            'avg_hollowness' => round($hollowSum / $n, 1),
        ];
    }

    /**
     * Normalize a candidate's "why" string into a stable key for cross-page aggregation.
     */
    private function candidateKey(string $why): string
    {
        $key = mb_strtolower(trim($why));
        $key = (string) preg_replace('/\b\d+\b/u', '#', $key);     // 11,847 / 38 / 6 → '#'
        $key = (string) preg_replace('/[^\p{L}\p{N}\s#]+/u', ' ', $key);
        $key = (string) preg_replace('/\s+/u', ' ', $key);

        return mb_strimwidth($key, 0, 80, '');
    }
}
