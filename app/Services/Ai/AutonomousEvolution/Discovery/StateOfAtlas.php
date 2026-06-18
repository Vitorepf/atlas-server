<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * STATE OF ATLAS — the loop's structured comprehension of Atlas, read once per cycle from the
 * brain. The rédea reads THIS to decide the biggest leap; without it the producer is blind.
 *
 * Pure value object: it holds the aggregated brain signals and answers the questions the leverage
 * decision needs (strategic alignment, forbidden zones, area maturity, what already exists). The
 * {@see AtlasLoopStateOfAtlasReader} assembles it; this class only queries it deterministically.
 */
final class StateOfAtlas
{
    /**
     * @param  list<array{text:string, keywords:list<string>}>  $strategic  active goals/decisions + keywords
     * @param  array<string,float>  $areaMaturity  area path-prefix => maturity 0..1 (LOWER = bigger opportunity)
     * @param  list<string>  $realityProvenPaths  paths the reality graph proves actually run
     * @param  list<string>  $forbiddenPrefixes  petreo / forbidden self-target path fragments
     */
    public function __construct(
        public readonly array $strategic = [],
        public readonly array $areaMaturity = [],
        public readonly array $realityProvenPaths = [],
        public readonly array $forbiddenPrefixes = [],
        public readonly int $elapsedMs = 0,
    ) {}

    /**
     * Strategic alignment of a path with where Atlas is actually going (active goals + decisions),
     * 0.30..1.0. Base 0.30 (the brain has no opinion) lifted by how many strategic items the path's
     * area/name touches. This is the "impacto_estratégico" of the leverage equation.
     */
    public function strategicWeightFor(string $path): float
    {
        if ($this->strategic === []) {
            return 0.30;
        }
        $hay = strtolower(ltrim($path, '/'));
        $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $hits = 0;
        foreach ($this->strategic as $item) {
            foreach ($item['keywords'] as $kw) {
                $kw = strtolower(trim((string) $kw));
                if ($kw !== '' && strlen($kw) >= 4 && (str_contains($hay, $kw) || str_contains($base, $kw))) {
                    $hits++;
                    break;
                }
            }
        }
        $frac = $hits / max(1, count($this->strategic));

        return max(0.30, min(1.0, 0.30 + 0.70 * min(1.0, $frac * 3.0))); // 1/3 of items touched saturates
    }

    /** Petreo / forbidden self-target: the loop must NEVER originate work here. */
    public function isForbidden(string $path): bool
    {
        $p = ltrim($path, '/');
        foreach ($this->forbiddenPrefixes as $f) {
            $f = ltrim((string) $f, '/');
            if ($f !== '' && ($p === $f || str_contains($p, $f))) {
                return true;
            }
        }

        return false;
    }

    /** Area maturity for a path (longest-prefix match). LOWER maturity = bigger opportunity. */
    public function maturityFor(string $path): float
    {
        $p = ltrim($path, '/');
        $best = 0.5; // unknown
        $bestLen = -1;
        foreach ($this->areaMaturity as $prefix => $m) {
            $prefix = ltrim((string) $prefix, '/');
            if ($prefix !== '' && str_starts_with($p, $prefix) && strlen($prefix) > $bestLen) {
                $best = (float) $m;
                $bestLen = strlen($prefix);
            }
        }

        return max(0.0, min(1.0, $best));
    }

    /**
     * Reality floor: is a capability described by these keywords ALREADY proven to run? Used to
     * stop the producer proposing what already exists (so it spends leaps on real gaps).
     *
     * @param  list<string>  $keywords
     */
    public function alreadyExists(array $keywords): bool
    {
        if ($this->realityProvenPaths === [] || $keywords === []) {
            return false;
        }
        $hay = strtolower(implode(' ', $this->realityProvenPaths));
        $matched = 0;
        foreach ($keywords as $kw) {
            $kw = strtolower(trim((string) $kw));
            if ($kw !== '' && strlen($kw) >= 4 && str_contains($hay, $kw)) {
                $matched++;
            }
        }

        // a strong majority of the capability's keywords already present in proven paths => exists.
        return $matched >= max(2, (int) ceil(count($keywords) * 0.6));
    }
}
