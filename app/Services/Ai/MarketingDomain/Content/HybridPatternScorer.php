<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingPatternOutcome;
use App\Services\Ai\MarketingDomain\Knowledge\PatternLibrary;

/**
 * HybridPatternScorer — the bridge that closes the flywheel. Same contract as PatternLibraryScorer,
 * but per-pattern weights are a blend of MY craft weights (encoded in the library) and LEARNED
 * weights from the LearnedWeightLedger for the given niche. Blend ratio depends on data sample:
 * 0 outcomes → 100% craft; 30 → 50/50; 200+ → 90% learned. This is what lets the OS calibrate
 * itself to a specific niche over time without ever throwing away the hand-encoded craft.
 */
class HybridPatternScorer
{
    public function __construct(private readonly LearnedWeightLedger $ledger = new LearnedWeightLedger) {}

    /**
     * @return array{library:string,score:int,present:array<int,string>,missing_high_leverage:array<int,array{key:string,name:string,lever:string,weight:int|float}>,by_category:array<string,int>,grade:string,blend:array{outcomes:int,craft_share:float,learned_share:float}}
     */
    public function score(PatternLibrary $library, string $copy, string $niche = '', string $pageKind = 'bridge'): array
    {
        $text = mb_strtolower($copy);
        $craft = $library->all();

        // Pull rows for the niche if asked; compute learned weights + sample size.
        $learned = [];
        $sample = 0;
        if ($niche !== '') {
            $rows = AiMarketingPatternOutcome::query()
                ->where('niche', $niche)
                ->where('page_kind', $pageKind)
                ->whereNotNull('conversion_rate')
                ->get(['present_patterns', 'conversion_rate'])
                ->map(fn ($r) => ['present_patterns' => (array) $r->present_patterns, 'conversion_rate' => (float) $r->conversion_rate])
                ->all();
            $sample = count($rows);
            if ($sample > 0) {
                $learned = $this->ledger->computeWeights($rows, 1);   // we manage the threshold via blend below
            }
        }

        $craftShare = $this->craftShare($sample);
        $learnedShare = 1.0 - $craftShare;

        $gotWeight = [];
        $maxWeight = [];
        foreach ($library->categories() as $c) {
            $gotWeight[$c] = 0.0;
            $maxWeight[$c] = 0.0;
        }

        $present = [];
        $missing = [];
        foreach ($craft as $p) {
            $craftW = (float) $p['weight'];
            $learnedW = ($learned[$library->name().':'.$p['key']] ?? 0.0) * 5.0;   // bring 0..1 lift into ~craft range
            $blended = $craftShare * $craftW + $learnedShare * $learnedW;
            $cat = $p['category'];
            $maxWeight[$cat] += $blended;
            if ($this->hits($text, $p['markers'])) {
                $present[] = $p['key'];
                $gotWeight[$cat] += $blended;
            } else {
                $missing[] = $p + ['weight' => $blended];
            }
        }

        $totalMax = array_sum($maxWeight) ?: 1;
        $score = (int) round(array_sum($gotWeight) / $totalMax * 100);

        $byCategory = [];
        foreach ($library->categories() as $c) {
            $byCategory[$c] = $maxWeight[$c] > 0 ? (int) round($gotWeight[$c] / $maxWeight[$c] * 100) : 0;
        }

        usort($missing, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $missingTop = array_map(
            static fn (array $p): array => ['key' => $p['key'], 'name' => $p['name'], 'lever' => $p['lever'], 'weight' => round((float) $p['weight'], 2)],
            array_slice($missing, 0, 6)
        );

        return [
            'library' => $library->name(),
            'score' => $score,
            'present' => $present,
            'missing_high_leverage' => $missingTop,
            'by_category' => $byCategory,
            'grade' => $this->grade($score),
            'blend' => ['outcomes' => $sample, 'craft_share' => round($craftShare, 2), 'learned_share' => round($learnedShare, 2)],
        ];
    }

    /**
     * Sigmoid-ish blend curve. 0 outcomes → 1.0 craft; 30 → 0.5; 200+ → ~0.1 craft.
     */
    private function craftShare(int $sample): float
    {
        if ($sample <= 0) {
            return 1.0;
        }
        $share = 30.0 / (30.0 + $sample);     // simple soft-max style
        return max(0.1, min(1.0, $share));
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function hits(string $text, array $markers): bool
    {
        foreach ($markers as $m) {
            if ($m === '') {
                continue;
            }
            if ($m[0] === '/') {
                if (@preg_match($m, $text) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($text, mb_strtolower($m))) {
                return true;
            }
        }

        return false;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 85 => 'killer',
            $score >= 70 => 'strong',
            $score >= 50 => 'decent',
            $score >= 30 => 'weak',
            default => 'flat',
        };
    }
}
