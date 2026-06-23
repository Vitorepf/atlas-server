<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\PersuasionPatternLibrary;

/**
 * PersuasionScorer — measures how hard a page pulls the known persuasion levers. It detects which
 * patterns from the PersuasionPatternLibrary are present (string or regex markers), produces a
 * weighted 0–100 persuasion score plus per-category coverage, and surfaces the highest-leverage
 * MISSING patterns with how to deploy them. This is the engine that lets Atlas understand *why* a page
 * converts and what to add to convert more. Deterministic.
 */
class PersuasionScorer
{
    public function __construct(private readonly PersuasionPatternLibrary $library = new PersuasionPatternLibrary) {}

    /**
     * @return array{score:int,present:array<int,string>,missing_high_leverage:array<int,array{key:string,name:string,lever:string}>,by_category:array<string,int>,grade:string}
     */
    public function score(string $copy): array
    {
        $text = mb_strtolower($copy);
        $patterns = $this->library->all();

        $present = [];
        $missing = [];
        $gotWeight = [];
        $maxWeight = [];
        foreach ($this->library->categories() as $c) {
            $gotWeight[$c] = 0;
            $maxWeight[$c] = 0;
        }

        foreach ($patterns as $p) {
            $cat = $p['category'];
            $maxWeight[$cat] = ($maxWeight[$cat] ?? 0) + $p['weight'];
            if ($this->hits($text, $p['markers'])) {
                $present[] = $p['key'];
                $gotWeight[$cat] += $p['weight'];
            } else {
                $missing[] = $p;
            }
        }

        $totalMax = array_sum($maxWeight) ?: 1;
        $totalGot = array_sum($gotWeight);
        $score = (int) round($totalGot / $totalMax * 100);

        $byCategory = [];
        foreach ($this->library->categories() as $c) {
            $byCategory[$c] = $maxWeight[$c] > 0 ? (int) round($gotWeight[$c] / $maxWeight[$c] * 100) : 0;
        }

        usort($missing, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $missingTop = array_map(
            static fn (array $p): array => ['key' => $p['key'], 'name' => $p['name'], 'lever' => $p['lever']],
            array_slice($missing, 0, 6)
        );

        return [
            'score' => $score,
            'present' => $present,
            'missing_high_leverage' => $missingTop,
            'by_category' => $byCategory,
            'grade' => $this->grade($score),
        ];
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
            if ($m[0] === '/') {                       // regex marker
                if (@preg_match($m, $text) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($text, mb_strtolower($m))) {  // substring marker
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
