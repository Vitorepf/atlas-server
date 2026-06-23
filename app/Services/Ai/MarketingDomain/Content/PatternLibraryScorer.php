<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\PatternLibrary;

/**
 * PatternLibraryScorer — the one scorer for the whole Conversion Pattern OS. Given any PatternLibrary
 * and a page's copy, it detects which patterns fire (string or /regex/ markers), returns a weighted
 * 0–100 score, per-category coverage, and the highest-leverage MISSING patterns with how to deploy
 * them. Every conversion dimension is measured through this single lens — so a funnel can be audited
 * across all libraries at once. Deterministic.
 */
class PatternLibraryScorer
{
    /**
     * @return array{library:string,score:int,present:array<int,string>,missing_high_leverage:array<int,array{key:string,name:string,lever:string}>,by_category:array<string,int>,grade:string}
     */
    public function score(PatternLibrary $library, string $copy): array
    {
        $text = mb_strtolower($copy);

        $gotWeight = [];
        $maxWeight = [];
        foreach ($library->categories() as $c) {
            $gotWeight[$c] = 0;
            $maxWeight[$c] = 0;
        }

        $present = [];
        $missing = [];
        foreach ($library->all() as $p) {
            $cat = $p['category'];
            $maxWeight[$cat] = ($maxWeight[$cat] ?? 0) + $p['weight'];
            if ($this->hits($text, $p['markers'])) {
                $present[] = $p['key'];
                $gotWeight[$cat] = ($gotWeight[$cat] ?? 0) + $p['weight'];
            } else {
                $missing[] = $p;
            }
        }

        $totalMax = array_sum($maxWeight) ?: 1;
        $score = (int) round(array_sum($gotWeight) / $totalMax * 100);

        $byCategory = [];
        foreach ($library->categories() as $c) {
            $byCategory[$c] = ($maxWeight[$c] ?? 0) > 0 ? (int) round($gotWeight[$c] / $maxWeight[$c] * 100) : 0;
        }

        usort($missing, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $missingTop = array_map(
            static fn (array $p): array => ['key' => $p['key'], 'name' => $p['name'], 'lever' => $p['lever']],
            array_slice($missing, 0, 6)
        );

        return [
            'library' => $library->name(),
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
