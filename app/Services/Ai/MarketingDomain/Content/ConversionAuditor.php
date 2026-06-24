<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\AngleBigIdeaLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\AwarenessSophisticationLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\CognitiveBiasLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\FunnelSequenceLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\HookLeadLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\NarrativeVoiceLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\ObjectionLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\OfferArchitectureLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\PatternLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\PersuasionPatternLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\VisualPersuasionLibrary;

/**
 * ConversionAuditor — unified multi-dimensional X-ray. Runs ALL libraries of the Conversion Pattern OS
 * against a page (copy + optional html) and returns a per-library score plus a single overall conversion
 * grade, plus a flat list of the top missing high-leverage patterns ACROSS all dimensions (weight-sorted).
 * This is the lens the Atlas uses to know exactly what a page is doing and what it isn't — the input
 * the AggressionAmplifier needs to inject what's missing.
 */
class ConversionAuditor
{
    /** @var array<int,PatternLibrary> Libraries that score on visible copy. */
    private array $copyLibraries;

    /** @var array<int,PatternLibrary> Libraries that score on HTML structure. */
    private array $structureLibraries;

    private HybridPatternScorer $hybrid;

    public function __construct(
        private readonly PatternLibraryScorer $scorer = new PatternLibraryScorer,
        ?HybridPatternScorer $hybrid = null,
        private readonly PersonaSimulator $personas = new PersonaSimulator,
        private readonly CopySmellDetector $smells = new CopySmellDetector,
        private readonly WatchThroughLeakDetector $leaks = new WatchThroughLeakDetector,
        private readonly ProofProvenanceAuditor $provenance = new ProofProvenanceAuditor,
        private readonly DecisionClarityAuditor $decision = new DecisionClarityAuditor,
    ) {
        // When the auditor is built without an explicit hybrid scorer, wire one by default — this
        // way passing a niche to audit() activates learned weights automatically (no rewiring needed
        // anywhere). The hybrid quietly returns craft-only when the ledger has no data.
        $this->hybrid = $hybrid ?? new HybridPatternScorer;
        $this->copyLibraries = [
            new AngleBigIdeaLibrary,
            new AwarenessSophisticationLibrary,
            new PersuasionPatternLibrary,
            new CognitiveBiasLibrary,
            new OfferArchitectureLibrary,
            new ObjectionLibrary,
            new HookLeadLibrary,
            new NarrativeVoiceLibrary,
            new FunnelSequenceLibrary,
        ];
        $this->structureLibraries = [new VisualPersuasionLibrary];
    }

    /**
     * @return array{overall_score:int,grade:string,by_library:array<string,array<string,mixed>>,top_missing:array<int,array{key:string,name:string,lever:string,library:string,weight:int}>}
     */
    public function audit(string $copy, string $html = '', string $niche = '', string $pageKind = 'bridge'): array
    {
        $byLibrary = [];
        $sum = 0;
        $count = 0;

        // When a niche is given AND a HybridPatternScorer is wired, the audit blends MY craft weights
        // with the niche's learned weights from the ledger. Otherwise it's pure craft (sane default).
        $useHybrid = $niche !== '' && $this->hybrid !== null;

        foreach ($this->copyLibraries as $lib) {
            $r = $useHybrid
                ? $this->hybrid->score($lib, $copy, $niche, $pageKind)
                : $this->scorer->score($lib, $copy);
            $byLibrary[$lib->name()] = $r;
            $sum += $r['score'];
            $count++;
        }
        foreach ($this->structureLibraries as $lib) {
            $r = $useHybrid
                ? $this->hybrid->score($lib, $html !== '' ? $html : $copy, $niche, $pageKind)
                : $this->scorer->score($lib, $html !== '' ? $html : $copy);
            $byLibrary[$lib->name()] = $r;
            $sum += $r['score'];
            $count++;
        }

        $overall = $count > 0 ? (int) round($sum / $count) : 0;

        $smellReport = $this->smells->inspect($copy);

        return [
            'overall_score' => $overall,
            'grade' => $this->grade($overall),
            'by_library' => $byLibrary,
            'top_missing' => $this->topMissing($byLibrary),
            'personas' => $this->personas->simulate($copy, $html, $niche),
            'audience_score' => round($this->personas->audienceScore($copy, $niche) * 100),
            'structural_flaws' => $this->leaks->detect($copy)['flaws'],
            'decision_flaws' => $this->decision->audit($copy)['flaws'],
            'requires_proof' => $this->provenance->audit($copy)['requires_proof'],
            'smells' => $smellReport['smells'],
            'smells_count' => $smellReport['n'],
            // Honesty layer (cycles 43-45 meta-lesson): never let a vocabulary prior pass as proven
            // conversion. Each signal is labeled by how much it can be trusted.
            'signal_confidence' => [
                'structural_truth' => ['structural_flaws', 'decision_flaws', 'requires_proof', 'smells'],
                'heuristic_prior' => ['by_library', 'audience_score', 'top_missing'],
                'calibrated' => $useHybrid ? ['by_library (niche='.$niche.', se ledger ≥30 outcomes)'] : [],
                'note' => 'structural_flaws = FATOS estruturais true-positive (vazamento de reveal/CTA). '
                    .'by_library + audience_score = PRIORS heurísticos (presença de padrão / reação de persona simulada), '
                    .'NÃO conversão provada — viram calibrados só quando o LearnedWeightLedger receber ≥30 outcomes reais por nicho. '
                    .'top_missing prioriza por peso de craft, não por lift provado. overall_score é média de priors: tratar como bússola, não verdade.',
            ],
        ];
    }

    /**
     * Flatten all libraries' missing_high_leverage into one weight-sorted list across the OS.
     *
     * @param  array<string,array<string,mixed>>  $byLibrary
     * @return array<int,array{key:string,name:string,lever:string,library:string,weight:int}>
     */
    private function topMissing(array $byLibrary): array
    {
        $libWeights = $this->patternWeights();
        $flat = [];
        foreach ($byLibrary as $libName => $r) {
            foreach (($r['missing_high_leverage'] ?? []) as $m) {
                $w = $libWeights[$libName][$m['key']] ?? 0;
                $flat[] = ['key' => $m['key'], 'name' => $m['name'], 'lever' => $m['lever'], 'library' => $libName, 'weight' => $w];
            }
        }
        usort($flat, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_slice($flat, 0, 10);
    }

    /**
     * Build a (library → patternKey → weight) map for cross-library sorting.
     *
     * @return array<string,array<string,int>>
     */
    private function patternWeights(): array
    {
        $out = [];
        foreach (array_merge($this->copyLibraries, $this->structureLibraries) as $lib) {
            $map = [];
            foreach ($lib->all() as $p) {
                $map[$p['key']] = $p['weight'];
            }
            $out[$lib->name()] = $map;
        }

        return $out;
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
