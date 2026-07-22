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
    private const ENVELOPE_CLASS_MAP = [
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
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $canonical = self::ENVELOPE_CLASS_MAP[$class] ?? null;
            if ($canonical === null || (! class_exists($canonical) && ! interface_exists($canonical))) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}
