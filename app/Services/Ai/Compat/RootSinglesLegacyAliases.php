<?php

declare(strict_types=1);

namespace App\Services\Ai\Compat;

/**
 * @deprecated Remove after the GOD-DEBULK root-singles compatibility cycle
 *             once deployed workers and queued payloads no longer reference
 *             root-level AI owner names.
 */
final class RootSinglesLegacyAliases
{
    /** @var array<class-string, class-string> */
    private const CLASS_MAP = [
        'App\\Services\\Ai\\AiContextPackBuilder' => 'App\\Services\\Ai\\Context\\AiContextPackBuilder',
        'App\\Services\\Ai\\AiContextSnapshotRecorder' => 'App\\Services\\Ai\\Context\\AiContextSnapshotRecorder',
        'App\\Services\\Ai\\AiConversationContextBuilder' => 'App\\Services\\Ai\\Context\\AiConversationContextBuilder',
        'App\\Services\\Ai\\AiConversationRecorder' => 'App\\Services\\Ai\\Context\\AiConversationRecorder',
        'App\\Services\\Ai\\AiDecisionReceiptRefreshService' => 'App\\Services\\Ai\\AtlasDecide\\AiDecisionReceiptRefreshService',
        'App\\Services\\Ai\\AiCouncilCoordinator' => 'App\\Services\\Ai\\Arena\\AiCouncilCoordinator',
        'App\\Services\\Ai\\AiExecutionPresentationState' => 'App\\Services\\Ai\\HumanSurface\\AiExecutionPresentationState',
        'App\\Services\\Ai\\AiInteractionSteeringService' => 'App\\Services\\Ai\\ControlPlane\\AiInteractionSteeringService',
        'App\\Services\\Ai\\AiIntentRouter' => 'App\\Services\\Ai\\Router\\AiIntentRouter',
        'App\\Services\\Ai\\AiPrompt' => 'App\\Services\\Ai\\ValueObjects\\AiPrompt',
        'App\\Services\\Ai\\AiSessionManager' => 'App\\Services\\Ai\\ConversationOps\\AiSessionManager',
        'App\\Services\\Ai\\AiSessionStateService' => 'App\\Services\\Ai\\ConversationOps\\AiSessionStateService',
        'App\\Services\\Ai\\AiThreadDeletionService' => 'App\\Services\\Ai\\ConversationOps\\AiThreadDeletionService',
        'App\\Services\\Ai\\AiThreadResolver' => 'App\\Services\\Ai\\ConversationOps\\AiThreadResolver',
        'App\\Services\\Ai\\AiPermissionDecision' => 'App\\Services\\Ai\\Governance\\AiPermissionDecision',
        'App\\Services\\Ai\\AiPermissionEngine' => 'App\\Services\\Ai\\Governance\\AiPermissionEngine',
        'App\\Services\\Ai\\AiPermissionEngineSupport' => 'App\\Services\\Ai\\Governance\\AiPermissionEngineSupport',
        'App\\Services\\Ai\\AiQualityActionService' => 'App\\Services\\Ai\\Analysis\\AiQualityActionService',
        'App\\Services\\Ai\\AiQualityEvaluator' => 'App\\Services\\Ai\\Analysis\\AiQualityEvaluator',
        'App\\Services\\Ai\\AiRuntimeBudgetService' => 'App\\Services\\Ai\\Policy\\AiRuntimeBudgetService',
        'App\\Services\\Ai\\AiSkill' => 'App\\Services\\Ai\\Skills\\AiSkill',
        'App\\Services\\Ai\\AiSkillStore' => 'App\\Services\\Ai\\Skills\\AiSkillStore',
        'App\\Services\\Ai\\AiStreamRecorder' => 'App\\Services\\Ai\\Streaming\\AiStreamRecorder',
        'App\\Services\\Ai\\AiSurfaceHandoffService' => 'App\\Services\\Ai\\Surface\\AiSurfaceHandoffService',
        'App\\Services\\Ai\\AiTraceArtifactsProjection' => 'App\\Services\\Ai\\Instrumentation\\AiTraceArtifactsProjection',
        'App\\Services\\Ai\\AiTraceEngineeringReviewProjection' => 'App\\Services\\Ai\\Instrumentation\\AiTraceEngineeringReviewProjection',
        'App\\Services\\Ai\\AiWorkerLogger' => 'App\\Services\\Ai\\Instrumentation\\AiWorkerLogger',
        'App\\Services\\Ai\\AtlasDialecticTensionService' => 'App\\Services\\Ai\\Context\\AtlasDialecticTensionService',
        'App\\Services\\Ai\\AtlasFinalResponseSanitizer' => 'App\\Services\\Ai\\Surface\\AtlasFinalResponseSanitizer',
        'App\\Services\\Ai\\AtlasAiPolicyService' => 'App\\Services\\Ai\\Policy\\AtlasAiPolicyService',
        'App\\Services\\Ai\\AtlasAiRuntimeSettings' => 'App\\Services\\Ai\\Policy\\AtlasAiRuntimeSettings',
        'App\\Services\\Ai\\AtlasDomainProfilePolicyService' => 'App\\Services\\Ai\\Policy\\AtlasDomainProfilePolicyService',
        'App\\Services\\Ai\\AtlasDomainProfileRegistry' => 'App\\Services\\Ai\\Policy\\AtlasDomainProfileRegistry',
        'App\\Services\\Ai\\AtlasEffectivePolicyComposer' => 'App\\Services\\Ai\\Policy\\AtlasEffectivePolicyComposer',
        'App\\Services\\Ai\\AtlasProviderProjectionAuditPurgePolicy' => 'App\\Services\\Ai\\Instrumentation\\AtlasProviderProjectionAuditPurgePolicy',
        'App\\Services\\Ai\\AtlasProviderProjectionAuditService' => 'App\\Services\\Ai\\Instrumentation\\AtlasProviderProjectionAuditService',
        'App\\Services\\Ai\\AtlasProviderProjectionService' => 'App\\Services\\Ai\\Instrumentation\\AtlasProviderProjectionService',
        'App\\Services\\Ai\\CompactionLossPolicy' => 'App\\Services\\Ai\\Compaction\\CompactionLossPolicy',
        'App\\Services\\Ai\\YouTubeKnowledgeIngestionService' => 'App\\Services\\Ai\\Knowledge\\YouTubeKnowledgeIngestionService',
        'App\\Services\\Ai\\YoutubeCanonicalProjection' => 'App\\Services\\Ai\\Knowledge\\YoutubeCanonicalProjection',
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
            if ($canonical === null || ! class_exists($canonical)) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}

RootSinglesLegacyAliases::register();
