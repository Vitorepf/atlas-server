<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * @deprecated Remove after the M3 compatibility cycle once migrated AcosMax
 *             FQCNs have drained from queues and deployed workers.
 */
final class AcosMaxNamespaceAlias
{
    /** @var array<class-string, class-string> */
    private const CLASS_MAP = [
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelope' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelope',
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelopeBridge' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelopeBridge',
        'App\\Services\\Ai\\AcosMax\\AemorOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\AemorOutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\CompoundingOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\CompoundingOutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\DevProceduralOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\DevProceduralOutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\AsefChunkIndexService' => 'App\\Services\\Ai\\Context\\Retrieval\\AsefChunkIndexService',
        'App\\Services\\Ai\\AcosMax\\AtlasCodeSymbolEmbeddingCoverageService' => 'App\\Services\\Ai\\Context\\Retrieval\\AtlasCodeSymbolEmbeddingCoverageService',
        'App\\Services\\Ai\\AcosMax\\AtlasKnowledgeItemEmbeddingCoverageService' => 'App\\Services\\Ai\\Context\\Retrieval\\AtlasKnowledgeItemEmbeddingCoverageService',
        'App\\Services\\Ai\\AcosMax\\CitationGroundingMeter' => 'App\\Services\\Ai\\Context\\Retrieval\\CitationGroundingMeter',
        'App\\Services\\Ai\\AcosMax\\DomainLexicalNormalizer' => 'App\\Services\\Ai\\Context\\Retrieval\\DomainLexicalNormalizer',
        'App\\Services\\Ai\\AcosMax\\GatedCorpusCandidateMiner' => 'App\\Services\\Ai\\Context\\Retrieval\\GatedCorpusCandidateMiner',
        'App\\Services\\Ai\\AcosMax\\GoldenCounterfactualReplayService' => 'App\\Services\\Ai\\Context\\Retrieval\\GoldenCounterfactualReplayService',
        'App\\Services\\Ai\\AcosMax\\Maxa04JinaV3DualReadLedger' => 'App\\Services\\Ai\\Context\\Retrieval\\Maxa04JinaV3DualReadLedger',
        'App\\Services\\Ai\\AcosMax\\Maxa04JinaV3DualReadService' => 'App\\Services\\Ai\\Context\\Retrieval\\Maxa04JinaV3DualReadService',
        'App\\Services\\Ai\\AcosMax\\ProvenanceWeightCalculator' => 'App\\Services\\Ai\\Context\\Retrieval\\ProvenanceWeightCalculator',
        'App\\Services\\Ai\\AcosMax\\RagxChainMechanismService' => 'App\\Services\\Ai\\Context\\Retrieval\\RagxChainMechanismService',
        'App\\Services\\Ai\\AcosMax\\RecallGapAggregator' => 'App\\Services\\Ai\\Context\\Retrieval\\RecallGapAggregator',
        'App\\Services\\Ai\\AcosMax\\AcosMaxLedgerRotationRegistry' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxLedgerRotationRegistry',
        'App\\Services\\Ai\\AcosMax\\AcosMaxLote2MeasureService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxLote2MeasureService',
        'App\\Services\\Ai\\AcosMax\\AcosMaxMeasureSeriesRegistry' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxMeasureSeriesRegistry',
        'App\\Services\\Ai\\AcosMax\\AcosMaxObraRetroService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxObraRetroService',
        'App\\Services\\Ai\\AcosMax\\AcosMaxParallelExecutionProtocol' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxParallelExecutionProtocol',
        'App\\Services\\Ai\\AcosMax\\AcosMaxProceduralSkillPromoterService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxProceduralSkillPromoterService',
        'App\\Services\\Ai\\AcosMax\\AcosMaxVerifiedShareService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxVerifiedShareService',
        'App\\Services\\Ai\\AcosMax\\AcosMaxWindowOrchestratorService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMaxWindowOrchestratorService',
        'App\\Services\\Ai\\AcosMax\\AcosMeasureSeriesFreshnessReader' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosMeasureSeriesFreshnessReader',
        'App\\Services\\Ai\\AcosMax\\AcosProgramCockpitService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AcosProgramCockpitService',
        'App\\Services\\Ai\\AcosMax\\AmbitionRungPolicy' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AmbitionRungPolicy',
        'App\\Services\\Ai\\AcosMax\\AtlasFlywheelFunnelService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AtlasFlywheelFunnelService',
        'App\\Services\\Ai\\AcosMax\\AtlasLocalModelIntegrityService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AtlasLocalModelIntegrityService',
        'App\\Services\\Ai\\AcosMax\\AtlasModelCapabilitySpecService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AtlasModelCapabilitySpecService',
        'App\\Services\\Ai\\AcosMax\\AtlasNCaptureDrillService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AtlasNCaptureDrillService',
        'App\\Services\\Ai\\AcosMax\\AtlasResourceBudgetService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AtlasResourceBudgetService',
        'App\\Services\\Ai\\AcosMax\\AttemptLifecycleLedger' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\AttemptLifecycleLedger',
        'App\\Services\\Ai\\AcosMax\\BeliefCascadeReverificationPlanner' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\BeliefCascadeReverificationPlanner',
        'App\\Services\\Ai\\AcosMax\\ComposedObraArcComposer' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\ComposedObraArcComposer',
        'App\\Services\\Ai\\AcosMax\\ComposedObraArcLifecycle' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\ComposedObraArcLifecycle',
        'App\\Services\\Ai\\AcosMax\\DogfoodingFrictionLeadMiner' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\DogfoodingFrictionLeadMiner',
        'App\\Services\\Ai\\AcosMax\\Esp09IndependentChallengerService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\Esp09IndependentChallengerService',
        'App\\Services\\Ai\\AcosMax\\EvidenceVisionThesisComposer' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\EvidenceVisionThesisComposer',
        'App\\Services\\Ai\\AcosMax\\EvidenceVisionThesisLifecycle' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\EvidenceVisionThesisLifecycle',
        'App\\Services\\Ai\\AcosMax\\ExecutionContextCooccurrenceService' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\ExecutionContextCooccurrenceService',
        'App\\Services\\Ai\\AcosMax\\ExploratoryBetsPortfolio' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\ExploratoryBetsPortfolio',
        'App\\Services\\Ai\\AcosMax\\PortfolioBudgetAllocator' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\PortfolioBudgetAllocator',
        'App\\Services\\Ai\\AcosMax\\PreReviewAdvisoryBand' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\PreReviewAdvisoryBand',
        'App\\Services\\Ai\\AcosMax\\PredictedImpactBand' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\PredictedImpactBand',
        'App\\Services\\Ai\\AcosMax\\PromotionProtocol' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\PromotionProtocol',
        'App\\Services\\Ai\\AcosMax\\ReactiveSaturationSignal' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\ReactiveSaturationSignal',
        'App\\Services\\Ai\\AcosMax\\StructuredFactSchemaMap' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\StructuredFactSchemaMap',
        'App\\Services\\Ai\\AcosMax\\Teto10PredictedRevertReviewDigest' => 'App\\Services\\Ai\\Cognition\\AcosProgram\\Teto10PredictedRevertReviewDigest',
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $canonical = self::CLASS_MAP[$class] ?? null;
            if ($canonical === null || (! class_exists($canonical) && ! interface_exists($canonical))) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}
