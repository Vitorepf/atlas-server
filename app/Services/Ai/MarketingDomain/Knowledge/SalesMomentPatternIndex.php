<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;

/**
 * SalesMomentPatternIndex — indexes every pattern in the Conversion OS by the SALES MOMENT it serves.
 *
 * The ~243 patterns across the 10+ libraries are tagged by PSYCHOLOGY (category) but not by the PHASE of
 * the sale (hook → lead → agitation → mechanism → proof → offer → close → objection → follow-up). A
 * master-seller blueprint (7-agent research) found 4 of 6 domains converge on needing this missing
 * coordinate — it is the enabling substrate for proof-adjacency, zone-by-zone abandon simulation, the
 * objection-loop close engine, and positional scoring.
 *
 * Additive & backwards-compatible: a pattern MAY declare an optional 'sales_moment' (string|array); those
 * that don't are INFERRED from category/key/name. No library is rewritten and PatternLibraryScorer ignores
 * the key, so scoring stays byte-identical (proven in the frozen test).
 *
 * The solid deterministic deliverable is byMoment()/index() — each phase's patterns, weight-sorted — the
 * substrate the downstream instruments (proof-adjacency, abandon simulation, objection loop) consume.
 * momentsCovered()/orphanMoments() are a HEURISTIC PRIOR compass, NOT ground truth: they inherit the
 * underlying libraries' marker noise (the cycles-43/value-equation lesson — marker presence is a vocabulary
 * prior, not substance). For the SUBSTANCE truth of a phase, delegate to the dedicated auditors
 * (ProofSubstanceAuditor for proof, ValueEquationAuditor for offer, DecisionClarityAuditor for close); the
 * orphan signal flags CANDIDATES for them to confirm. Provider-free; dark patterns indexed too, no filter.
 */
class SalesMomentPatternIndex
{
    public const MOMENTS = ['hook', 'lead', 'agitation', 'mechanism', 'proof', 'offer', 'close', 'objection', 'follow_up'];

    /** Ordered keyword → moment inference (first match wins; checked against category, key, then name). */
    private const INFER = [
        'objection' => 'objection', 'rebuttal' => 'objection',
        'hook' => 'hook',
        'follow' => 'follow_up', 'email' => 'follow_up', 'nurture' => 'follow_up',
        'mechanism' => 'mechanism', 'awareness' => 'mechanism', 'sophistic' => 'mechanism',
        'guarantee' => 'close', 'risk_revers' => 'close', 'risk-revers' => 'close', 'scarcity' => 'close',
        'urgency' => 'close', 'deadline' => 'close', 'cta' => 'close', 'close' => 'close', 'commitment' => 'close', 'pressure' => 'close',
        'offer' => 'offer', 'value' => 'offer', 'bonus' => 'offer', 'stack' => 'offer', 'price' => 'offer', 'pricing' => 'offer',
        'proof' => 'proof', 'authority' => 'proof', 'credib' => 'proof', 'social' => 'proof', 'testimon' => 'proof',
        'fear' => 'agitation', 'pain' => 'agitation', 'problem' => 'agitation', 'enemy' => 'agitation',
        'conspirac' => 'agitation', 'forbidden' => 'agitation', 'agitat' => 'agitation', 'identity' => 'agitation', 'guilt' => 'agitation',
        'lead' => 'lead', 'angle' => 'lead', 'big_idea' => 'lead', 'big idea' => 'lead', 'headline' => 'lead',
    ];

    /** @var array<int,PatternLibrary> */
    private array $libraries;

    /**
     * @param  array<int,PatternLibrary>|null  $libraries
     */
    public function __construct(?array $libraries = null)
    {
        $this->libraries = $libraries ?? [
            new AngleBigIdeaLibrary,
            new AwarenessSophisticationLibrary,
            new PersuasionPatternLibrary,
            new CognitiveBiasLibrary,
            new OfferArchitectureLibrary,
            new ObjectionLibrary,
            new HookLeadLibrary,
            new NarrativeVoiceLibrary,
            new FunnelSequenceLibrary,
            new VisualPersuasionLibrary,
            new AggressiveConversionTacticsLibrary,
        ];
    }

    /**
     * The full moment → patterns map (weight-desc within each moment). Deterministic, cacheable.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function index(): array
    {
        $out = array_fill_keys(self::MOMENTS, []);
        foreach ($this->libraries as $lib) {
            foreach ($lib->all() as $p) {
                foreach ($this->momentsOf($p) as $moment) {
                    $out[$moment][] = [
                        'key' => $p['key'], 'name' => $p['name'], 'library' => $lib->name(),
                        'sales_moment' => $moment, 'weight' => (int) $p['weight'],
                        'trigger' => $p['trigger'] ?? '', 'lever' => $p['lever'] ?? '',
                        'markers' => $p['markers'] ?? [],
                    ];
                }
            }
        }
        foreach ($out as &$patterns) {
            usort($patterns, static fn ($a, $b) => $b['weight'] <=> $a['weight']);
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function byMoment(string $moment): array
    {
        return $this->index()[$this->normalize($moment)] ?? [];
    }

    /**
     * Per-moment coverage % of the copy (sum of present-pattern weights / total moment weight). Reuses the
     * PatternLibraryScorer marker-matching (its 'present' list) — no regex reimplemented here.
     *
     * @return array<string,int>
     */
    public function momentsCovered(string $copy, ?PatternLibraryScorer $scorer = null): array
    {
        $scorer ??= new PatternLibraryScorer;
        $present = [];
        foreach ($this->libraries as $lib) {
            foreach (($scorer->score($lib, $copy)['present'] ?? []) as $key) {
                $present[$key] = true;
            }
        }
        $cov = [];
        foreach ($this->index() as $moment => $patterns) {
            $total = 0;
            $hit = 0;
            foreach ($patterns as $p) {
                $total += $p['weight'];
                if (isset($present[$p['key']])) {
                    $hit += $p['weight'];
                }
            }
            $cov[$moment] = $total > 0 ? (int) round($hit / $total * 100) : 0;
        }

        return $cov;
    }

    /** A pattern at/above this weight is a "strong device" for its phase. */
    private const HIGH_LEVERAGE = 4;

    /**
     * Sales moments where NONE of the phase's strong devices (weight ≥ HIGH_LEVERAGE) fired — a whole
     * phase effectively missing. Defined on high-leverage presence (not coverage==0) so a single incidental
     * low-weight marker in the big offer/close buckets can't mask a genuinely absent phase. Structural FACT,
     * not a gameable score.
     *
     * @return array<int,string>
     */
    public function orphanMoments(string $copy, ?PatternLibraryScorer $scorer = null): array
    {
        $scorer ??= new PatternLibraryScorer;
        $present = [];
        foreach ($this->libraries as $lib) {
            foreach (($scorer->score($lib, $copy)['present'] ?? []) as $key) {
                $present[$key] = true;
            }
        }
        $orphans = [];
        foreach ($this->index() as $moment => $patterns) {
            $strong = array_filter($patterns, static fn ($p) => $p['weight'] >= self::HIGH_LEVERAGE);
            if ($strong === []) {
                continue; // phase has no strong device to require
            }
            $anyStrongPresent = false;
            foreach ($strong as $p) {
                if (isset($present[$p['key']])) {
                    $anyStrongPresent = true;
                    break;
                }
            }
            if (! $anyStrongPresent) {
                $orphans[] = $moment;
            }
        }

        return $orphans;
    }

    /**
     * Resolve a pattern's sales moment(s): explicit 'sales_moment' wins, else inferred. Returns [] if it
     * maps to no distinct phase (cross-phase pattern — excluded from phase analysis, never mis-bucketed).
     *
     * @param  array<string,mixed>  $p
     * @return array<int,string>
     */
    private function momentsOf(array $p): array
    {
        if (isset($p['sales_moment'])) {
            $declared = is_array($p['sales_moment']) ? $p['sales_moment'] : [$p['sales_moment']];
            $valid = array_values(array_filter(array_map(fn ($m) => $this->normalize((string) $m), $declared), static fn ($m) => $m !== ''));
            if ($valid !== []) {
                return array_values(array_unique($valid));
            }
        }
        $hay = mb_strtolower((string) ($p['category'] ?? '').' '.($p['key'] ?? '').' '.($p['name'] ?? ''));
        foreach (self::INFER as $needle => $moment) {
            if (str_contains($hay, $needle)) {
                return [$moment];
            }
        }

        return [];
    }

    private function normalize(string $moment): string
    {
        $m = str_replace([' ', '-'], '_', mb_strtolower(trim($moment)));

        return in_array($m, self::MOMENTS, true) ? $m : '';
    }
}
