<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * SCHEMA CONTRACT REGISTRY — pattern-design organ. Hand-curated registry mapping each brain
 * organ's SCHEMA constant string to the owning class. Lets downstream surfaces (digests, exports,
 * cross-process consumers) resolve "what does schema X mean" without reflecting over disk.
 *
 * Pure const lookup. Pétreo: réu would alias one schema to another to swap an organ's output
 * behind a consumer's back.
 */
final class AtlasBrainSchemaContractRegistry
{
    public const SCHEMA = 'atlas.brain.schema_contract_registry.v1';

    private const REGISTRY = [
        AtlasBrainPathYieldMomentum::SCHEMA => AtlasBrainPathYieldMomentum::class,
        AtlasBrainPathDiversityScore::SCHEMA => AtlasBrainPathDiversityScore::class,
        AtlasBrainPathOscillationDetector::SCHEMA => AtlasBrainPathOscillationDetector::class,
        AtlasBrainPlanAdviserRedTeam::SCHEMA => AtlasBrainPlanAdviserRedTeam::class,
        AtlasBrainNextCycleProjector::SCHEMA => AtlasBrainNextCycleProjector::class,
        AtlasBrainScopeFlagAuditor::SCHEMA => AtlasBrainScopeFlagAuditor::class,
        AtlasBrainCompoundingVelocity::SCHEMA => AtlasBrainCompoundingVelocity::class,
        AtlasBrainHintFrequencyDriftAlarm::SCHEMA => AtlasBrainHintFrequencyDriftAlarm::class,
        AtlasBrainCrossScopePatternXref::SCHEMA => AtlasBrainCrossScopePatternXref::class,
        AtlasBrainCriticalConsensusGate::SCHEMA => AtlasBrainCriticalConsensusGate::class,
        AtlasBrainHypotheticalTailAppender::SCHEMA => AtlasBrainHypotheticalTailAppender::class,
        AtlasBrainPathSignalAggregator::SCHEMA => AtlasBrainPathSignalAggregator::class,
        AtlasBrainPathPriorityRank::SCHEMA => AtlasBrainPathPriorityRank::class,
        AtlasBrainFrontierMethodCatalog::SCHEMA => AtlasBrainFrontierMethodCatalog::class,
        AtlasBrainGateFalsePositiveEstimator::SCHEMA => AtlasBrainGateFalsePositiveEstimator::class,
        AtlasBrainProjectionCalibrationScore::SCHEMA => AtlasBrainProjectionCalibrationScore::class,
        AtlasBrainReflectionProvenanceChain::SCHEMA => AtlasBrainReflectionProvenanceChain::class,
        AtlasBrainCoverageMatrix::SCHEMA => AtlasBrainCoverageMatrix::class,
        AtlasBrainStaleEvidenceVeto::SCHEMA => AtlasBrainStaleEvidenceVeto::class,
        AtlasBrainPathYieldEwma::SCHEMA => AtlasBrainPathYieldEwma::class,
        AtlasBrainConcentrationHhi::SCHEMA => AtlasBrainConcentrationHhi::class,
        AtlasBrainHintBurstDetector::SCHEMA => AtlasBrainHintBurstDetector::class,
        AtlasBrainPathInactivityAlarm::SCHEMA => AtlasBrainPathInactivityAlarm::class,
        AtlasBrainAuthorJudgeOverlapCheck::SCHEMA => AtlasBrainAuthorJudgeOverlapCheck::class,
        AtlasBrainRepeatedRefusalAntiPattern::SCHEMA => AtlasBrainRepeatedRefusalAntiPattern::class,
        AtlasBrainPathStreakTracker::SCHEMA => AtlasBrainPathStreakTracker::class,
        AtlasBrainGreedyRotationProjector::SCHEMA => AtlasBrainGreedyRotationProjector::class,
        AtlasBrainPortfolioBudgetAllocator::SCHEMA => AtlasBrainPortfolioBudgetAllocator::class,
    ];

    public function ownerOf(string $schema): ?string
    {
        return self::REGISTRY[$schema] ?? null;
    }

    /**
     * @return list<array{schema:string, owner:string}>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::REGISTRY as $schema => $owner) {
            $out[] = ['schema' => $schema, 'owner' => $owner];
        }

        return $out;
    }

    public function count(): int
    {
        return count(self::REGISTRY);
    }
}
