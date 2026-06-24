<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

/**
 * QUATERNITY · CORTEX-INTENT-MEANING — the ambiguity verdict. It consumes the rows produced by the
 * triangulator (each row a grounded {intent, symbol, file, evidence, matched_token}) and emits a pure FACT the
 * loop branches on: does the intent ground to EXACTLY ONE real (symbol,file) site, or not?
 *
 * Anti-Goodhart invariant: there are NO knobs, NO tunable cutoffs, NO scores, NO settings reads. The verdict is
 * purely the count of DISTINCT (symbol,file) sites:
 *   - 0 sites  → not ambiguous, but cannot proceed → clarification required (fail-closed, never silent).
 *   - 1 site   → unique grounding → the only path that proceeds without clarification.
 *   - ≥2 sites → ambiguous → clarification required.
 *
 * A site mentioned twice is still ONE site (dedup is real), so repetition never manufactures ambiguity.
 */
final class AtlasCortexIntentMeaningAmbiguityDetector
{
    /**
     * @param  list<array<string,mixed>>|array<int,mixed>  $triangulationFacts
     * @return array{ambiguous:bool, site_count:int, distinct_symbols:list<string>, distinct_files:list<string>, clarification_required:bool, reason:string}
     */
    public function detect(array $triangulationFacts): array
    {
        $sites = [];
        $symbols = [];
        $files = [];

        foreach ($triangulationFacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $symbol = trim((string) ($row['symbol'] ?? ''));
            $file = trim((string) ($row['file'] ?? ''));
            if ($symbol === '' && $file === '') {
                continue; // an ungrounded row is not a site
            }
            $sites[$symbol.'@'.$file] = true;
            if ($symbol !== '') {
                $symbols[$symbol] = true;
            }
            if ($file !== '') {
                $files[$file] = true;
            }
        }

        $distinctSymbols = array_keys($symbols);
        sort($distinctSymbols);
        $distinctFiles = array_keys($files);
        sort($distinctFiles);
        $siteCount = count($sites);

        if ($siteCount === 0) {
            return $this->verdict(false, 0, [], [], true, 'no_grounded_site');
        }
        if ($siteCount === 1) {
            return $this->verdict(false, 1, $distinctSymbols, $distinctFiles, false, 'unique_grounded_site');
        }

        return $this->verdict(true, $siteCount, $distinctSymbols, $distinctFiles, true, 'multiple_grounded_sites');
    }

    /**
     * @param  list<string>  $distinctSymbols
     * @param  list<string>  $distinctFiles
     * @return array{ambiguous:bool, site_count:int, distinct_symbols:list<string>, distinct_files:list<string>, clarification_required:bool, reason:string}
     */
    private function verdict(bool $ambiguous, int $siteCount, array $distinctSymbols, array $distinctFiles, bool $clarificationRequired, string $reason): array
    {
        return [
            'ambiguous' => $ambiguous,
            'site_count' => $siteCount,
            'distinct_symbols' => $distinctSymbols,
            'distinct_files' => $distinctFiles,
            'clarification_required' => $clarificationRequired,
            'reason' => $reason,
        ];
    }
}
