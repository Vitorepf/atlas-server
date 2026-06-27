<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * ORGAN DEPENDENCY GRAPH — comprehension-deepening organ. Hand-curated map of which other brain
 * organs each consuming organ depends on (declared via the documented build/aggregate signatures,
 * not runtime introspection). Lets operators answer "if I change X, what breaks downstream?"
 * without scanning files.
 *
 * Pure const map. Pétreo: réu would hide consumers to launder a breaking change.
 */
final class AtlasBrainOrganDependencyGraph
{
    public const SCHEMA = 'atlas.brain.organ_dependency_graph.v1';

    /** @var array<class-string, list<class-string>> */
    private const DEPENDS_ON = [
        AtlasBrainNextCycleProjector::class => [
            AtlasBrainPlanAdviserRedTeam::class,
            AtlasBrainPathYieldMomentum::class,
        ],
        AtlasBrainPathSignalAggregator::class => [
            AtlasBrainPathYieldMomentum::class,
            AtlasBrainCompoundingVelocity::class,
            AtlasBrainPathOscillationDetector::class,
        ],
        AtlasBrainPathPriorityRank::class => [
            AtlasBrainPathSignalAggregator::class,
        ],
        AtlasBrainPortfolioBudgetAllocator::class => [
            AtlasBrainPathPriorityRank::class,
        ],
        AtlasBrainPerceptionBundle::class => [
            AtlasBrainReflectionStream::class,
            AtlasBrainBriefHistogram::class,
            AtlasBrainResultKindHistogram::class,
            AtlasBrainHintEntropy::class,
            AtlasBrainHintTransitionMatrix::class,
            AtlasBrainTrendAnalyzer::class,
            AtlasBrainEvidenceFreshness::class,
            AtlasBrainHintToPathTranslator::class,
            AtlasBrainPathStarvationDetector::class,
            AtlasBrainCascadeRuleOutcomeAnalyzer::class,
            AtlasBrainPathDiversityScore::class,
            AtlasBrainConcentrationHhi::class,
            AtlasBrainPathYieldMomentum::class,
            AtlasBrainPathOscillationDetector::class,
            AtlasBrainHintBurstDetector::class,
            AtlasBrainRepeatedRefusalAntiPattern::class,
            AtlasBrainCompoundingVelocity::class,
            AtlasBrainPathYieldEwma::class,
            AtlasBrainPathStreakTracker::class,
            AtlasBrainPathSignalAggregator::class,
        ],
        AtlasBrainSchemaContractRegistry::class => [
            // virtual edge: registry references every owner schema constant; not iterated here
        ],
        AtlasBrainHorizonDiversityForecast::class => [
            AtlasBrainGreedyRotationProjector::class,
        ],
        AtlasBrainCompoundingSuperposition::class => [
            AtlasBrainPathYieldMomentum::class,
            AtlasBrainCompoundingVelocity::class,
            AtlasBrainPathYieldEwma::class,
            AtlasBrainPathStreakRatio::class,
        ],
        AtlasBrainComposedSelfKnowledgeReport::class => [
            AtlasBrainCoverageMatrix::class,
            AtlasBrainOrganDependencyGraph::class,
            AtlasBrainFrontierMethodCatalog::class,
            AtlasBrainSchemaContractRegistry::class,
        ],
    ];

    /**
     * @return list<class-string>
     */
    public function dependsOn(string $organClass): array
    {
        return self::DEPENDS_ON[$organClass] ?? [];
    }

    /**
     * @return list<class-string>
     */
    public function consumersOf(string $organClass): array
    {
        $out = [];
        foreach (self::DEPENDS_ON as $consumer => $deps) {
            if (in_array($organClass, $deps, true)) {
                $out[] = (string) $consumer;
            }
        }

        return $out;
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    public function graph(): array
    {
        return self::DEPENDS_ON;
    }
}
